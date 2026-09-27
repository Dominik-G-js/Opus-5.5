"""Bezpečnostní sonda: 66 útoků na čistou kopii aplikace (přístup bez přihlášení, session fixation, SQL injection,
hádání hesla, XSS, CSRF, škodlivé soubory, podvržená doména, open redirect, path traversal, únik tajemství…).

Spuštění z kořene projektu:  python3 tests/e2e/security.py        (vyžaduje PHP a pip install requests)
"""
import atexit, os, re, subprocess, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from harness import USER, PASSWORD, AppUnderTest, csrf, session  # noqa: E402

_app = AppUnderTest(8770).__enter__()
atexit.register(_app.__exit__, None, None, None)
B = _app.base
A = _app.admin
PW = PASSWORD
results = []
def check(ok, name, detail=""):
    results.append((ok, name, detail))
    print(("OK   " if ok else "FAIL ") + name + (f"  [{detail}]" if detail and not ok else ""))

def sess():
    return session()

def login(s, user=USER, pw=PW):
    r = s.get(A + "/login")
    return s.post(A + "/login", data={"_csrf": csrf(r.text), "username": user, "password": pw}, allow_redirects=False)

def post(s, path, data, files=None):
    t = csrf(s.get(A + "/settings").text)
    return s.post(A + path, data=dict(data, _csrf=t), files=files, allow_redirects=False)

# 1) Přístup bez přihlášení
anon = sess()
for p in ["", "/models", "/models/1", "/models/1/bible", "/images/1", "/earnings", "/earnings/export", "/costs/export",
          "/fans", "/fans/1", "/settings", "/accounts/1", "/integrations/fanvue/callback?code=x&state=y", "/links", "/tools"]:
    r = anon.get(A + p, allow_redirects=False)
    check(r.status_code == 303 and r.headers.get("Location", "").endswith("/admin/login"), f"bez přihlášení GET {p} → login", str(r.status_code))
# POST bez tokenu i s tokenem, ale nepřihlášený
r = anon.post(A + "/models", data={"name": "x"}, allow_redirects=False)
check(r.status_code == 419, "POST bez CSRF tokenu → 419", str(r.status_code))
t = csrf(anon.get(A + "/login").text)
r = anon.post(A + "/models", data={"_csrf": t, "name": "x", "persona_age": "30", "status": "concept", "page_lang": "en"}, allow_redirects=False)
check(r.status_code == 303 and r.headers["Location"].endswith("/login"), "POST s tokenem, ale nepřihlášený → login", str(r.status_code))

# 2) Session fixation
fx = sess()
fx.cookies.set("ams_sid", "attackerchosenid1234567890abcdef", domain="127.0.0.1", path="/admin")
login(fx)
sid = fx.cookies.get("ams_sid", path="/admin")
check(sid is not None and sid != "attackerchosenid1234567890abcdef", "session fixation: po přihlášení nové ID")

# 3) Přihlášení: SQL injection a brute force
r = login(sess(), "' OR '1'='1", "' OR '1'='1")
check(r.headers.get("Location", "").endswith("/login"), "SQLi v loginu nepřihlásí")
bf = sess()
for _ in range(5):
    login(bf, USER, "spatne-heslo")
r = login(bf, USER, PW)
check(r.status_code == 429, "brute force: po 5 chybách zablokováno i se správným heslem", str(r.status_code))
# jiná IP (reálně) by prošla — lokálně jen ověříme, že blok platí na účet+IP; vyčistíme pokusy
_app.db("DELETE FROM login_attempts")

s = sess()
r = login(s)
check(r.status_code == 303 and r.headers["Location"].endswith("/admin"), "platné přihlášení")
cookie_hdr = r.headers.get("Set-Cookie", "")
check("HttpOnly" in cookie_hdr and "SameSite=Lax" in cookie_hdr, "session cookie HttpOnly + SameSite", cookie_hdr)

# 4) Bezpečnostní hlavičky
h = s.get(A).headers
check("default-src 'self'" in h.get("Content-Security-Policy", "") and "script-src 'self'" in h.get("Content-Security-Policy", ""), "CSP bez inline skriptů")
check(h.get("X-Frame-Options") == "DENY" and "frame-ancestors 'none'" in h.get("Content-Security-Policy", ""), "ochrana proti clickjackingu")
check(h.get("X-Content-Type-Options") == "nosniff", "nosniff")
check(h.get("Cache-Control") == "no-store" and "noindex" in h.get("X-Robots-Tag", ""), "admin: no-store + noindex")
check("X-Powered-By" not in h, "neprozrazuje verzi PHP")

# příprava dat: modelka, účet v CZK a jedna platba
r = post(s, "/models", {"name": "Sonda", "status": "active", "persona_age": "30", "page_lang": "en"})
sonda = re.search(r"/models/(\d+)$", r.headers["Location"]).group(1)
r = post(s, f"/models/{sonda}/accounts", {"platform_id": "1", "handle": "sonda", "status": "active", "currency": "CZK"})
acc = re.search(r"/accounts/(\d+)$", r.headers["Location"]).group(1)
post(s, "/earnings", {"account_id": acc, "occurred_on": "2026-01-15", "type": "tip", "gross": "100"})

# 5) SQL injection v parametrech
for q in ["?month=2025-01'%20OR%201=1--", "?model=1%20OR%201=1", "?type='%3BDROP%20TABLE%20transactions--", "?account=-1%20UNION%20SELECT%201"]:
    r = s.get(A + "/earnings" + q)
    check(r.status_code == 200 and "Něco se pokazilo" not in r.text, f"SQLi v /earnings{q[:20]}… bez chyby")
cnt = _app.db("SELECT COUNT(*) FROM transactions")[0][0]
check(cnt == 1, "tabulka transakcí nedotčená", str(cnt))

# 6) XSS — škodlivé vstupy v modelce, publikace a kontrola výstupu
payload_name = "<script>alert('xss')</script>"
payload_tag = "\"><img src=x onerror=alert(1)>"
payload_bio = "</script><script>alert(2)</script>"
r = post(s, "/models", {"name": payload_name, "slug": "xss-test", "status": "active", "persona_age": "30", "page_lang": "en",
                        "tagline": payload_tag, "public_bio": payload_bio, "page_published": "1", "seo_title": payload_tag})
mid = re.search(r"/models/(\d+)$", r.headers.get("Location", ""))
check(mid is not None, "vytvoření modelky se škodlivými vstupy")
pages = {"admin detail": s.get(A + f"/models/{mid.group(1)}").text, "admin seznam": s.get(A + "/models").text,
         "bible": s.get(A + f"/models/{mid.group(1)}/bible").text, "landing": sess().get(B + "/m/xss-test").text}
for name, html in pages.items():
    bad = ["<script>alert", "<img src=x onerror", "</script><script>"]
    found = [b for b in bad if b in html]
    check(not found, f"XSS: {name} escapuje vstupy", ",".join(found))
ld = re.search(r'<script type="application/ld\+json">(.*?)</script>', pages["landing"], re.S)
check(ld is not None and "<" not in ld.group(1), "XSS: JSON-LD bez '<' (nelze ukončit <script>)")

# 7) CSRF a Origin
r = s.post(A + "/settings/goal", data={"_csrf": "0" * 64, "goal": "1", "goal_basis": "net"}, allow_redirects=False)
check(r.status_code == 419, "špatný CSRF token → 419", str(r.status_code))
t = csrf(s.get(A + "/settings").text)
r = s.post(A + "/settings/goal", data={"_csrf": t, "goal": "1", "goal_basis": "net"}, headers={"Origin": "https://evil.example"}, allow_redirects=False)
check(r.status_code == 403, "cizí Origin → 403", str(r.status_code))

# 8) Upload škodlivých souborů
tests = {
    "php jako png": ("shell.png", b"<?php system($_GET['c']); ?>", "image/png"),
    "svg se skriptem": ("x.svg", b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "image/svg+xml"),
    "html": ("x.html", b"<html><script>alert(1)</script></html>", "text/html"),
}
for name, (fn, data, mime) in tests.items():
    before = _app.db("SELECT COUNT(*) FROM images")
    post(s, f"/models/{mid.group(1)}/images", {}, files={"image": (fn, data, mime)})
    after = _app.db("SELECT COUNT(*) FROM images")
    check(before == after, f"upload odmítnut: {name}")
bomb = subprocess.run(["php", "-r", '$i=imagecreate(9000,9000);imagecolorallocate($i,0,0,0);ob_start();imagepng($i,null,9);echo ob_get_clean();'], capture_output=True).stdout
before = _app.db("SELECT COUNT(*) FROM images")
post(s, f"/models/{mid.group(1)}/images", {}, files={"image": ("bomb.png", bomb, "image/png")})
after = _app.db("SELECT COUNT(*) FROM images")
check(before == after, f"upload odmítnut: dekompresní bomba 81 Mpx ({len(bomb)//1024} kB)")
check(not any(p.endswith(".php") for p in os.listdir(os.path.join(_app.dir, "storage", "uploads"))), "v uploads není žádný .php")

# 9) Host header a vlastní domény
r = sess().get(B + "/admin/login", headers={"Host": "evil.example"}, allow_redirects=False)
check(r.status_code == 404, "cizí Host → administrace nedostupná", str(r.status_code))
post(s, f"/models/{mid.group(1)}", {"name": "XSS test", "slug": "xss-test", "status": "active", "persona_age": "30", "page_lang": "en",
                                    "page_published": "1", "page_domain": "xsstest.example"})
r = sess().get(B + "/", headers={"Host": "xsstest.example"})
check(r.status_code == 200 and "XSS test" in r.text, "vlastní doména zobrazí landing page")
r = sess().get(B + "/admin/login", headers={"Host": "xsstest.example"}, allow_redirects=False)
check(r.status_code == 404, "na doméně modelky není administrace", str(r.status_code))
r = sess().get(B + "/", headers={"Host": "www.xsstest.example"}, allow_redirects=False)
check(r.status_code == 301 and r.headers["Location"] == "https://xsstest.example/", "www → bez www (301)")

# 10) Open redirect, path traversal, skryté soubory
r = sess().get(B + "/go/neexistuje", allow_redirects=False)
check(r.status_code == 404, "/go/ jen na uložené odkazy")
r = sess().get(A + "/login?next=https://evil.example", allow_redirects=False)
check("evil.example" not in r.text, "login nemá parametr pro přesměrování")
for p in ["/../config/config.php", "/..%2fconfig%2fconfig.php", "/storage/database.sqlite", "/media/p/..%2f..%2fconfig.jpg", "/config/config.php", "/.htaccess", "/index.php/../../config/config.php"]:
    r = sess().get(B + p, allow_redirects=False)
    check(r.status_code in (403, 404) and "app_key" not in r.text and "SQLite format" not in r.text, f"nedostupné: {p}", str(r.status_code))
r = sess().get(B + "/robots.txt")
check("admin" not in r.text, "robots.txt neprozrazuje cestu k administraci")
r = sess().get(B + "/")
check(r.status_code == 404, "kořen hlavní domény → 404 (neprozrazuje admin)")

# 11) Nezveřejněná modelka: stránka ani obrázky nejsou vidět
post(s, f"/models/{mid.group(1)}", {"name": "XSS test", "slug": "xss-test", "status": "active", "persona_age": "30", "page_lang": "en"})
r = sess().get(B + "/m/xss-test")
check(r.status_code == 404, "nezveřejněná stránka → 404")
r = sess().get(B + "/", headers={"Host": "xsstest.example"})
check(r.status_code == 404, "nezveřejněná doména → 404")

# 12) Věk postavy pod 21 odmítnut i při obejití formuláře
r = post(s, "/models", {"name": "Test age", "status": "concept", "persona_age": "17", "page_lang": "en"})
check(r.headers["Location"].endswith("/models/new"), "věk 17 odmítnut na serveru")

# 13) Mass assignment — podvržená pole se ignorují
r = post(s, f"/models/{mid.group(1)}", {"name": "XSS test", "slug": "xss-test", "status": "active", "persona_age": "30", "page_lang": "en",
                                        "id": "1", "created_at": "2000-01-01 00:00:00"})
created = _app.db("SELECT created_at FROM models WHERE slug = 'xss-test'")[0][0]
check(not created.startswith("2000"), "podvržené created_at ignorováno")

# 14) Tajemství v DB šifrovaná
row = _app.db("SELECT COALESCE(totp_secret_enc, '-') FROM users")[0][0]
check(row == "-" or row.startswith("v1:"), "2FA tajemství v DB šifrované", row[:10])
perm = [oct(os.stat(os.path.join(_app.dir, p)).st_mode & 0o777)[2:] for p in ("config/config.php", "storage/database.sqlite")]
check(perm == ["600", "600"], "config.php a databáze jen pro vlastníka (0600)", " ".join(perm))

fails = [r for r in results if not r[0]]
print(f"\n{len(results) - len(fails)}/{len(results)} OK")
sys.exit(1 if fails else 0)
