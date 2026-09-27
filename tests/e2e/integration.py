"""Integrační test napojení na Fanvue a ČNB přes skutečné HTTPS (cURL v PHP), bez internetu.

Spustí vlastní certifikační autoritu, HTTPS napodobeninu Fanvue (OAuth 2.0 + PKCE, rotace refresh tokenů,
stránkování kurzorem, 429 s Retry-After) a ČNB API, nainstaluje čistou kopii aplikace s konfigurací
namířenou na napodobeninu a projde celý scénář: připojení účtu → synchronizace → opakovaná synchronizace
→ CLI cron → zneplatnění tokenu → kurzy ČNB → odpojení.

Spuštění (z kořene projektu):  python3 tests/e2e/integration.py      (vyžaduje PHP, openssl, pip install requests)
"""
import base64, datetime as dt, hashlib, json, os, re, secrets, shutil, ssl, subprocess, sys, tempfile, threading, time
import urllib.parse as up
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import requests

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from harness import AppUnderTest, Checker, csrf, login  # noqa: E402

MOCK_PORT, APP_PORT = 9443, 8767
MOCK = f"https://127.0.0.1:{MOCK_PORT}"
CLIENT_ID, CLIENT_SECRET = "test-client", "test-secret-" + secrets.token_hex(8)
check = Checker()


# ---------------------------------------------------------------- napodobenina Fanvue + ČNB
class MockState:
    def __init__(self):
        today = dt.datetime.now(dt.timezone.utc).replace(hour=12, minute=0, second=0, microsecond=0)
        fans = [
            {"uuid": "11111111-1111-4111-8111-111111111111", "handle": "mock-whale", "displayName": "Mock Whale", "nickname": None, "isTopSpender": True},
            {"uuid": "22222222-2222-4222-8222-222222222222", "handle": "mock-fan", "displayName": "Mock Fan", "nickname": None, "isTopSpender": False},
        ]
        sources = ["subscription", "renewal", "tip", "message", "post", "mediaLink"]
        self.earnings = []
        for i in range(60):  # víc než jedna stránka (50) → testuje kurzor
            fan = None if i % 15 == 0 else fans[0 if i % 3 == 0 else 1]
            when = today - dt.timedelta(days=i % 20, minutes=i)
            self.earnings.append({"date": when.strftime("%Y-%m-%dT%H:%M:%S.000Z"), "gross": 1000 + i * 10,
                                  "net": int((1000 + i * 10) * 0.85), "source": "referral" if fan is None else sources[i % 6], "user": fan})
        self.codes, self.access, self.refresh_valid, self.used_refresh = {}, set(), set(), []
        self.calls, self.rate_limited = [], False
        self.cnb_down = set()

    def cnb_rate(self, day):  # deterministický kurz podle data
        return round(22.0 + day.day / 100, 3)


STATE = MockState()


class MockHandler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _json(self, code, data, headers=None):
        body = json.dumps(data).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _bearer_ok(self):
        auth = self.headers.get("Authorization", "")
        return auth.startswith("Bearer ") and auth[7:] in STATE.access

    def do_GET(self):
        url = up.urlparse(self.path)
        q = dict(up.parse_qsl(url.query))
        STATE.calls.append({"method": "GET", "path": url.path, "query": q, "headers": dict(self.headers)})
        if url.path == "/oauth2/auth":
            ok = (q.get("response_type") == "code" and q.get("client_id") == CLIENT_ID and q.get("code_challenge_method") == "S256"
                  and "read:insights" in q.get("scope", "") and "offline_access" in q.get("scope", "") and len(q.get("state", "")) >= 32)
            if not ok:
                return self._json(400, {"error": "invalid_request"})
            code = secrets.token_hex(16)
            STATE.codes[code] = {"challenge": q["code_challenge"], "redirect_uri": q["redirect_uri"]}
            self.send_response(302)
            self.send_header("Location", q["redirect_uri"] + "?" + up.urlencode({"code": code, "state": q["state"]}))
            self.end_headers()
            return
        if url.path == "/cnbapi/exrates/daily-currency-month":
            currency, ym = q.get("currency", ""), q.get("yearMonth", "")
            if currency in STATE.cnb_down:
                return self._json(503, {"error": "down"})
            year, month = map(int, ym.split("-"))
            day, rates = dt.date(year, month, 1), []
            while day.month == month and day <= dt.date.today():
                if day.weekday() < 5:
                    rates.append({"validFor": day.isoformat(), "order": 1, "amount": 1, "currencyCode": currency, "rate": STATE.cnb_rate(day)})
                day += dt.timedelta(days=1)
            return self._json(200, {"rates": rates})
        # Fanvue API — vyžaduje token a verzi API
        if not self._bearer_ok():
            return self._json(401, {"error": "unauthorized"})
        if self.headers.get("X-Fanvue-API-Version") != "2025-06-26":
            return self._json(400, {"error": "missing api version"})
        if url.path == "/users/me":
            return self._json(200, {"uuid": "99999999-9999-4999-8999-999999999999", "handle": "mock-creator", "displayName": "Mock Creator",
                                    "email": "x@example.com", "bio": "", "isCreator": True, "createdAt": "2025-01-01T00:00:00.000Z",
                                    "updatedAt": None, "avatarUrl": None, "bannerUrl": None})
        if url.path in ("/insights/earnings", "/insights/subscribers"):
            if url.path == "/insights/earnings" and not STATE.rate_limited:
                STATE.rate_limited = True
                return self._json(429, {"error": "rate limited"}, {"Retry-After": "1"})
            start, end = q["startDate"], q["endDate"]
            size = min(50, int(q.get("size", "20")))
            if url.path == "/insights/earnings":
                items = [e for e in STATE.earnings if start <= e["date"] < end]
            else:
                days = sorted({e["date"][:10] for e in STATE.earnings if start <= e["date"] < end})
                items = [{"date": d + "T00:00:00.000Z", "total": 0, "newSubscribersCount": 2, "cancelledSubscribersCount": 1} for d in days]
            offset = int(base64.b64decode(q["cursor"]).decode()) if "cursor" in q else 0
            page = items[offset:offset + size]
            nxt = base64.b64encode(str(offset + size).encode()).decode() if offset + size < len(items) else None
            return self._json(200, {"data": page, "nextCursor": nxt})
        return self._json(404, {"error": "not found"})

    def do_POST(self):
        url = up.urlparse(self.path)
        length = int(self.headers.get("Content-Length", "0"))
        form = dict(up.parse_qsl(self.rfile.read(length).decode()))
        STATE.calls.append({"method": "POST", "path": url.path, "form": form, "headers": dict(self.headers)})
        if url.path != "/oauth2/token":
            return self._json(404, {})
        expected = "Basic " + base64.b64encode(f"{CLIENT_ID}:{CLIENT_SECRET}".encode()).decode()
        if self.headers.get("Authorization") != expected:
            return self._json(401, {"error": "invalid_client"})
        if form.get("grant_type") == "authorization_code":
            grant = STATE.codes.pop(form.get("code", ""), None)
            challenge = base64.urlsafe_b64encode(hashlib.sha256(form.get("code_verifier", "").encode()).digest()).rstrip(b"=").decode()
            if grant is None or grant["redirect_uri"] != form.get("redirect_uri") or grant["challenge"] != challenge:
                return self._json(400, {"error": "invalid_grant", "error_description": "PKCE / code / redirect_uri"})
        elif form.get("grant_type") == "refresh_token":
            token = form.get("refresh_token", "")
            if token not in STATE.refresh_valid:
                return self._json(400, {"error": "invalid_grant", "error_description": "refresh token revoked or reused"})
            STATE.refresh_valid.discard(token)  # rotace: starý token už neplatí
            STATE.used_refresh.append(token)
        else:
            return self._json(400, {"error": "unsupported_grant_type"})
        access, refresh = "at-" + secrets.token_hex(8), "rt-" + secrets.token_hex(8)
        STATE.access.add(access)
        STATE.refresh_valid.add(refresh)
        # expires_in 60 s → aplikace ho považuje za prošlý hned (rezerva 60 s) a při každé synchronizaci obnovuje = test rotace
        return self._json(200, {"access_token": access, "refresh_token": refresh, "expires_in": 60, "token_type": "bearer",
                                "scope": "openid offline_access offline read:self read:insights read:fan"})


def make_certs(directory):
    ca_key, ca_crt = os.path.join(directory, "ca.key"), os.path.join(directory, "ca.pem")
    key, csr, crt, ext = (os.path.join(directory, n) for n in ("srv.key", "srv.csr", "srv.pem", "ext.cnf"))
    run = lambda *a: subprocess.run(a, check=True, capture_output=True)
    run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", ca_key, "-out", ca_crt, "-days", "2", "-subj", "/CN=AMS Test CA")
    run("openssl", "req", "-newkey", "rsa:2048", "-nodes", "-keyout", key, "-out", csr, "-subj", "/CN=127.0.0.1")
    with open(ext, "w") as f:
        f.write("subjectAltName=IP:127.0.0.1\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n")
    run("openssl", "x509", "-req", "-in", csr, "-CA", ca_crt, "-CAkey", ca_key, "-CAcreateserial", "-out", crt, "-days", "2", "-extfile", ext)
    return ca_crt, crt, key


def flashes(html):
    return " | ".join(m.strip() for m in re.findall(r'class="flash flash-\w+"[^>]*>([^<]*)', html))


def patch_config(cfg):
    cfg = cfg.replace("'client_id' => ''", f"'client_id' => '{CLIENT_ID}'").replace("'client_secret' => ''", f"'client_secret' => '{CLIENT_SECRET}'")
    cfg = cfg.replace("'api_version' => '2025-06-26',", f"'api_version' => '2025-06-26', 'auth_base' => '{MOCK}', 'api_base' => '{MOCK}',")
    return cfg.rstrip().rstrip("];") + f"\n    'cnb' => ['api_url' => '{MOCK}/cnbapi/exrates/daily-currency-month'],\n];\n"


def main():
    work = tempfile.mkdtemp(prefix="ams-mock-")
    try:
        ca, crt, key = make_certs(work)
        server = ThreadingHTTPServer(("127.0.0.1", MOCK_PORT), MockHandler)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(crt, key)
        server.socket = ctx.wrap_socket(server.socket, server_side=True)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        # PHP (cURL) důvěřuje jen testovací CA — jinak se ověřuje certifikát stejně jako v produkci.
        with AppUnderTest(APP_PORT, patch_config, ["-d", f"curl.cainfo={ca}", "-d", f"openssl.cafile={ca}"]) as app:
            run_scenario(app, ca)
        server.shutdown()
    finally:
        shutil.rmtree(work, ignore_errors=True)
    return check.summary()


def run_scenario(app, ca):
        ADMIN, db = app.admin, app.db
        s = login(app)

        def post(path, data):
            return s.post(ADMIN + path, data=dict(data, _csrf=csrf(s.get(ADMIN + "/settings").text)), allow_redirects=False)

        # Modelka + Fanvue účet
        r = post("/models", {"name": "Integrace Test", "status": "active", "persona_age": "25", "page_lang": "en"})
        model_id = re.search(r"/models/(\d+)$", r.headers["Location"]).group(1)
        r = post(f"/models/{model_id}/accounts", {"platform_id": "1", "handle": "", "status": "active", "sync_since": ""})
        check(r.status_code == 303 and "/models/" in r.headers["Location"], "účet bez jména odmítnut (validace)")
        r = post(f"/models/{model_id}/accounts", {"platform_id": "1", "handle": "integrace", "status": "active"})
        account_id = re.search(r"/accounts/(\d+)$", r.headers["Location"]).group(1)
        page = s.get(ADMIN + f"/accounts/{account_id}").text
        check("Připojit Fanvue" in page, "účet nabízí připojení Fanvue")

        # 1) Chybný state v callbacku
        r = post(f"/accounts/{account_id}/fanvue/connect", {})
        s.get(ADMIN + "/integrations/fanvue/callback?code=x&state=" + "0" * 48, allow_redirects=False)
        check("Neplatný stav OAuth" in s.get(ADMIN + f"/accounts/{account_id}").text, "podvržený state v OAuth odmítnut")

        # 2) Připojení (OAuth + PKCE)
        r = post(f"/accounts/{account_id}/fanvue/connect", {})
        auth_url = r.headers.get("Location", "")
        q = dict(up.parse_qsl(up.urlparse(auth_url).query))
        check(auth_url.startswith(MOCK + "/oauth2/auth"), "přesměrování na autorizační server", auth_url[:80])
        check(q.get("redirect_uri") == ADMIN + "/integrations/fanvue/callback" and q.get("code_challenge_method") == "S256", "redirect_uri a PKCE S256")
        csp = r.headers.get("Content-Security-Policy", "")
        check(f"form-action 'self' {MOCK}" in csp, "CSP form-action povoluje autorizační server")
        r = requests.get(auth_url, verify=ca, allow_redirects=False)
        callback = r.headers["Location"]
        r = s.get(callback, allow_redirects=False)
        page = s.get(ADMIN + f"/accounts/{account_id}").text
        check("Fanvue účet připojen" in page and "připojeno" in page, "OAuth připojení dokončeno", flashes(page))
        token_calls = [c for c in STATE.calls if c["path"] == "/oauth2/token"]
        check(len(token_calls) == 1 and token_calls[0]["form"].get("grant_type") == "authorization_code", "výměna kódu s PKCE a Basic auth prošla")
        enc = db("SELECT credentials_enc, external_uuid FROM accounts WHERE id = ?", account_id)[0]
        check(enc[0].startswith("v1:") and "rt-" not in enc[0] and "at-" not in enc[0], "tokeny v DB šifrované")
        check(enc[1] == "99999999-9999-4999-8999-999999999999", "UUID tvůrce uložené z /users/me")

        # 3) Synchronizace (refresh tokenu, 429, stránkování, kurzy ČNB přes HTTPS)
        t0 = time.time()
        r = post(f"/accounts/{account_id}/sync", {})
        page = s.get(ADMIN + f"/accounts/{account_id}").text
        check("Synchronizováno: 60 plateb" in page, "první synchronizace stáhla 60 plateb", flashes(page))
        check(time.time() - t0 >= 1, "po 429 aplikace počkala podle Retry-After")
        earn = [c for c in STATE.calls if c["path"] == "/insights/earnings"]
        check(any("cursor" in c["query"] for c in earn), "stránkování kurzorem použito")
        check(all(c["headers"].get("X-Fanvue-API-Version") == "2025-06-26" for c in earn), "hlavička X-Fanvue-API-Version")
        check(len(STATE.used_refresh) == 1, "prošlý token obnoven refresh tokenem", str(STATE.used_refresh))
        rows = db("SELECT occurred_on, fx_rate, net_minor, net_czk_minor, type FROM transactions WHERE account_id = ?", account_id)
        check(len(rows) == 60, "60 transakcí v DB", str(len(rows)))

        def expected_rate(day):
            d = dt.date.fromisoformat(day)
            while d.weekday() >= 5:
                d -= dt.timedelta(days=1)
            return STATE.cnb_rate(d)

        php_round = lambda x: int(x + 0.5) if x >= 0 else -int(-x + 0.5)  # PHP round(): 0,5 od nuly (Python zaokrouhluje k sudé)
        bad = [r for r in rows if abs(r[1] - expected_rate(r[0])) > 1e-9 or r[3] != php_round(r[2] * r[1])]
        check(not bad, "kurz ČNB ke dni platby (víkend = pátek) a přepočet na CZK", str(bad[:2]))
        check({"media_link", "referral", "subscription"} <= {r[4] for r in rows}, "typy plateb namapované (mediaLink → media_link)")
        fans = s.get(ADMIN + "/fans").text
        spend = {}
        for e in STATE.earnings:
            if e["user"]:
                spend[e["user"]["displayName"]] = spend.get(e["user"]["displayName"], 0) + e["net"]
        top, second = sorted(spend, key=spend.get, reverse=True)
        check(-1 < fans.find(top) < fans.find(second), "fanoušci z API seřazení podle útraty", f"{top} > {second}")
        check(db("SELECT is_top_spender FROM fans WHERE external_id = ?", "11111111-1111-4111-8111-111111111111")[0][0] == 1, "top spender z API")
        check(db("SELECT SUM(new_subscribers) FROM subscriber_stats_daily")[0][0] > 0, "denní statistiky předplatitelů uložené")

        # 4) Opakovaná synchronizace: idempotentní, rotace refresh tokenu
        post(f"/accounts/{account_id}/sync", {})
        check(len(db("SELECT id FROM transactions WHERE account_id = ?", account_id)) == 60, "opakovaná synchronizace nezdvojí data")
        check(len(STATE.used_refresh) == 2 and STATE.used_refresh[0] != STATE.used_refresh[1], "rotovaný refresh token uložen a použit")

        # 5) CLI (cron)
        cli = app.console("sync")
        check(cli.returncode == 0 and "✔ @integrace: 60 plateb" in cli.stdout, "cron: php bin/console sync", cli.stdout + cli.stderr)

        # 6) Zneplatněný refresh token → srozumitelná chyba + výzva k novému připojení
        STATE.refresh_valid.clear()
        post(f"/accounts/{account_id}/sync", {})
        page = s.get(ADMIN + f"/accounts/{account_id}").text
        check("připojit znovu" in page.lower() or "Připojit Fanvue" in flashes(page), "zneplatněný token → výzva k novému připojení", flashes(page))
        check("selhala" in s.get(ADMIN).text, "chyba synchronizace je vidět na přehledu")
        check(len(db("SELECT id FROM transactions WHERE account_id = ?", account_id)) == 60, "data po chybě zůstala")

        # 7) Kurzy ČNB přes HTTPS: víkend, výpadek bez uložených dat, výpadek s uloženými daty, ruční kurz
        last_sat = dt.date.today() - dt.timedelta(days=(dt.date.today().weekday() - 5) % 7 or 7)
        post("/earnings", {"account_id": account_id, "occurred_on": last_sat.isoformat(), "type": "tip", "gross": "10", "currency": "USD"})
        rate = db("SELECT fx_rate FROM transactions WHERE source = 'manual' AND currency = 'USD'")[0][0]
        check(abs(rate - STATE.cnb_rate(last_sat - dt.timedelta(days=1))) < 1e-9, "ruční příjem v sobotu → kurz z pátku")

        STATE.cnb_down.add("EUR")  # EUR se ještě nikdy nestahovalo
        r = post("/earnings", {"account_id": account_id, "occurred_on": dt.date.today().isoformat(), "type": "tip", "gross": "10", "currency": "EUR"})
        page = s.get(ADMIN + "/earnings/new").text
        check(r.headers["Location"].endswith("/earnings/new") and "ČNB" in page, "výpadek ČNB bez uložených kurzů → výzva k ručnímu kurzu")
        post("/earnings", {"account_id": account_id, "occurred_on": dt.date.today().isoformat(), "type": "tip", "gross": "10", "currency": "EUR", "fx_rate": "24,9"})
        check(db("SELECT fx_rate FROM transactions WHERE currency = 'EUR'") == [(24.9,)], "ruční kurz použit")

        post("/settings/rates", {})
        check("Kurzy ČNB aktualizovány" not in s.get(ADMIN + "/settings").text, "obnova kurzů při výpadku EUR ohlásí chybu")
        STATE.cnb_down.clear()
        post("/settings/rates", {})
        check("Kurzy ČNB aktualizovány" in s.get(ADMIN + "/settings").text, "tlačítko Aktualizovat kurzy")
        db("UPDATE exchange_rate_months SET fetched_at = '2000-01-01 00:00:00'")  # vynutit obnovu běžícího měsíce
        STATE.cnb_down.add("GBP")  # GBP je uložené → při výpadku se použije
        today = dt.date.today()
        r = post("/earnings", {"account_id": account_id, "occurred_on": today.isoformat(), "type": "tip", "gross": "10", "currency": "GBP"})
        location = r.headers.get("Location", "")
        check("/admin/earnings" in location and not location.endswith("/earnings/new"), "výpadek ČNB s uloženými kurzy → platba uložena", location)
        check(abs(db("SELECT fx_rate FROM transactions WHERE currency = 'GBP'")[0][0] - expected_rate(today.isoformat())) < 1e-9, "použit poslední uložený kurz")

        # 8) Export pro účetní obsahuje data z API
        csv = s.get(ADMIN + "/earnings/export").text
        check("Mock Whale" in csv and "Fanvue API" in csv, "CSV export obsahuje platby z API")

        # 9) Odpojení
        post(f"/accounts/{account_id}/disconnect", {})
        check(db("SELECT integration, credentials_enc FROM accounts WHERE id = ?", account_id)[0] == ("none", None), "odpojení smaže tokeny")


if __name__ == "__main__":
    sys.exit(main())
