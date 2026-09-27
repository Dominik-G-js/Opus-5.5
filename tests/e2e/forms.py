"""Průchod VŠEMI formuláři a akcemi administrace + crawler všech odkazů (stavové kódy, chybové stránky).

Nainstaluje čistou kopii aplikace (prázdná DB), nic dalšího není potřeba spouštět.
Spuštění z kořene projektu:  python3 tests/e2e/forms.py        (vyžaduje PHP a pip install requests)
"""
import csv, io, os, re, subprocess, sys
from collections import deque
from urllib.parse import urljoin, urlparse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from harness import AppUnderTest, Checker, csrf, flashes, login, session  # noqa: E402

check = Checker()


def image_bytes(fmt, w=600, h=750):
    code = f'$i=imagecreatetruecolor({w},{h});imagefill($i,0,0,imagecolorallocate($i,120,90,160));ob_start();image{fmt}($i);echo ob_get_clean();'
    return subprocess.run(["php", "-r", code], capture_output=True, check=True).stdout


def main():
    with AppUnderTest(8769) as app:
        s = login(app)
        A = app.admin

        def get(path, expect=200):
            r = s.get(A + path, allow_redirects=False)
            ok = r.status_code == expect and not any(x in r.text for x in ("Fatal error", "Warning:", "Deprecated:", "Něco se pokazilo"))
            check(ok, f"GET {path or '/'} → {expect}", f"{r.status_code}")
            return r

        def post(path, data, files=None):
            token = csrf(s.get(A + "/settings").text)
            return s.post(A + path, data=dict(data, _csrf=token), files=files, allow_redirects=False)

        def follow(r):
            return s.get(urljoin(app.base, r.headers["Location"])) if "Location" in r.headers else r

        def has_error(r, text):
            page = follow(r).text
            return text in page

        # ---------------------------------------------------------------- 0) prázdná instalace
        dash = get("").text
        check("Začni první AI modelkou" in dash and dash.count("+ Přidat AI modelku") >= 3, "prázdný přehled: výzva a tlačítka Přidat AI modelku")
        for path in ["/earnings/new", "/links/new", "/earnings/import", "/models"]:
            check("+ Přidat AI modelku" in get(path).text, f"{path}: tlačítko Přidat AI modelku")
        check(app.db("SELECT COUNT(*) FROM models")[0][0] == 0 and app.db("SELECT COUNT(*) FROM transactions")[0][0] == 0, "žádná demo data v čisté instalaci")
        check(app.db("SELECT COUNT(*) FROM platforms")[0][0] == 9 and app.db("SELECT COUNT(*) FROM ai_tools")[0][0] == 10, "předvyplněné platformy a AI nástroje z rešerše")

        # ---------------------------------------------------------------- 1) modelky
        check(has_error(post("/models", {"name": "", "status": "concept", "persona_age": "25", "page_lang": "en"}), "Jméno: povinné pole"), "modelka: prázdné jméno")
        check(has_error(post("/models", {"name": "X", "status": "concept", "persona_age": "20", "page_lang": "en"}), "Věk postavy"), "modelka: věk pod 21")
        check(has_error(post("/models", {"name": "X", "slug": "Bad Slug", "status": "concept", "persona_age": "25", "page_lang": "en"}), "URL slug"), "modelka: neplatný slug")
        check(has_error(post("/models", {"name": "X", "status": "hacked", "persona_age": "25", "page_lang": "en"}), "Stav: neplatná hodnota"), "modelka: podvržený stav")
        r = post("/models", {"name": "Žaneta Nováková", "status": "building", "persona_age": "27", "page_lang": "cs", "niche": "Fitness",
                             "look_face": "oval face", "public_bio": "Řádek 1\nŘádek 2", "page_domain": "zaneta-test.com"})
        m1 = int(re.search(r"/models/(\d+)$", r.headers["Location"]).group(1))
        check(app.db("SELECT slug FROM models WHERE id = ?", m1) == [("zaneta-novakova",)], "slug z českého jména")
        check(has_error(post("/models", {"name": "Jiná", "status": "concept", "persona_age": "25", "page_lang": "en", "page_domain": "zaneta-test.com"}), "Doménu už používá"), "modelka: duplicitní doména")
        r = post("/models", {"name": "Druhá Modelka", "status": "concept", "persona_age": "30", "page_lang": "en"})
        m2 = int(re.search(r"/models/(\d+)$", r.headers["Location"]).group(1))
        get(f"/models/{m1}"); get(f"/models/{m1}/edit"); get(f"/models/{m1}/bible")
        check(f'/admin/models/{m1}/edit">upravit' in get("/models").text, "seznam modelek: odkaz upravit")
        r = post(f"/models/{m1}", {"name": "Žaneta Nováková", "slug": "zaneta-novakova", "status": "active", "persona_age": "28", "page_lang": "cs",
                                   "tagline": "Hory a fitness", "page_published": "1", "seo_title": "Žaneta — AI", "page_domain": "", "look_face": "oval face"})
        check(app.db("SELECT status, persona_age, page_published, page_domain FROM models WHERE id = ?", m1) == [("active", 28, 1, None)], "úprava modelky uložena")

        # ---------------------------------------------------------------- 2) AI nástroje
        check(has_error(post("/tools", {"name": "Kling 3.0", "category": "video", "pricing_model": "per_use", "currency": "USD"}), "už existuje"), "nástroj: duplicitní název")
        check(has_error(post("/tools", {"name": "X", "category": "video", "pricing_model": "per_use", "currency": "USD", "url": "javascript:alert(1)"}), "platnou adresu"), "nástroj: javascript: URL odmítnuta")
        post("/tools", {"name": "Midjourney", "category": "image", "pricing_model": "subscription", "monthly_price": "30", "currency": "USD", "url": "https://www.midjourney.com"})
        tool = app.db("SELECT id, monthly_price_minor FROM ai_tools WHERE name = 'Midjourney'")[0]
        check(tool[1] == 3000, "nástroj: měsíční cena uložena v centech")
        get(f"/tools/{tool[0]}/edit")
        post(f"/tools/{tool[0]}", {"name": "Midjourney v8", "category": "image", "pricing_model": "subscription", "monthly_price": "", "currency": "USD"})
        check(app.db("SELECT name, monthly_price_minor FROM ai_tools WHERE id = ?", tool[0]) == [("Midjourney v8", None)], "nástroj upraven")
        post(f"/models/{m1}/tools", {"tool_id": str(tool[0]), "purpose": "portréty"})
        check(app.db("SELECT purpose FROM model_tools WHERE model_id = ? AND tool_id = ?", m1, tool[0]) == [("portréty",)], "nástroj přiřazen modelce")
        check('name="purpose" value="portréty"' in get(f"/models/{m1}").text, "účel nástroje jde upravit přímo na profilu")
        post(f"/models/{m1}/tools", {"tool_id": str(tool[0]), "purpose": "trénink LoRA"})
        check(app.db("SELECT purpose FROM model_tools WHERE model_id = ? AND tool_id = ?", m1, tool[0]) == [("trénink LoRA",)], "účel nástroje upraven")
        post(f"/models/{m1}/tools/{tool[0]}/detach", {})
        check(app.db("SELECT COUNT(*) FROM model_tools WHERE model_id = ?", m1)[0][0] == 0, "nástroj odebrán z modelky")
        post(f"/models/{m1}/tools", {"tool_id": str(tool[0])})
        post(f"/tools/{tool[0]}/delete", {})
        check(app.db("SELECT COUNT(*) FROM model_tools WHERE tool_id = ?", tool[0])[0][0] == 0, "smazání nástroje odstraní vazby")

        # ---------------------------------------------------------------- 3) platformy
        post("/platforms", {"name": "Telegram", "role": "traffic", "ai_policy": "unknown", "default_fee_percent": "0", "default_currency": "USD"})
        check(has_error(post("/platforms", {"name": "telegram", "role": "traffic", "ai_policy": "unknown", "default_fee_percent": "0", "default_currency": "USD"}), "už existuje"), "platforma: duplicitní název (bez ohledu na velikost)")
        check(has_error(post("/platforms", {"name": "Y", "role": "traffic", "ai_policy": "unknown", "default_fee_percent": "150", "default_currency": "USD"}), "Poplatek"), "platforma: poplatek > 100 %")
        pid = app.db("SELECT id FROM platforms WHERE name = 'Telegram'")[0][0]
        post(f"/platforms/{pid}", {"name": "Telegram", "role": "both", "ai_policy": "allowed", "default_fee_percent": "5,5", "default_currency": "EUR"})
        check(app.db("SELECT role, default_fee_percent, default_currency FROM platforms WHERE id = ?", pid) == [("both", 5.5, "EUR")], "platforma upravena")
        check("Smazat platformu" in get(f"/platforms/{pid}/edit").text, "nepoužitá platforma má tlačítko Smazat")
        post(f"/platforms/{pid}/delete", {})
        check(app.db("SELECT COUNT(*) FROM platforms WHERE id = ?", pid)[0][0] == 0, "platforma smazána")

        # ---------------------------------------------------------------- 4) prompty
        r = get(f"/models/{m1}/prompts/new?kind=character_base")
        check("photo of a 28-year-old woman, oval face" in r.text, "master prompt předvyplněný z character bible")
        post(f"/models/{m1}/prompts", {"kind": "image", "title": "A", "prompt": "p1", "is_master": "1"})
        post(f"/models/{m1}/prompts", {"kind": "image", "title": "B", "prompt": "p2", "is_master": "1"})
        check(app.db("SELECT title FROM prompts WHERE model_id = ? AND is_master = 1", m1) == [("B",)], "jen jeden master na typ")
        check(has_error(post(f"/models/{m1}/prompts", {"kind": "image", "title": "", "prompt": ""}), "Název: povinné pole"), "prompt: povinná pole")
        pa = app.db("SELECT id FROM prompts WHERE title = 'A'")[0][0]
        post(f"/prompts/{pa}", {"kind": "image", "title": "A", "prompt": "p1 upraveno", "seed": "5", "rating": "4"})
        check(app.db("SELECT COUNT(*) FROM prompt_versions WHERE prompt_id = ?", pa)[0][0] == 1, "úprava promptu uloží starou verzi")
        post(f"/prompts/{pa}", {"kind": "image", "title": "A2", "prompt": "p1 upraveno", "seed": "5", "rating": "4"})
        check(app.db("SELECT COUNT(*) FROM prompt_versions WHERE prompt_id = ?", pa)[0][0] == 1, "změna jen názvu verzi neukládá")
        ver = app.db("SELECT id FROM prompt_versions WHERE prompt_id = ?", pa)[0][0]
        pb = app.db("SELECT id FROM prompts WHERE title = 'B'")[0][0]
        check("smazat verzi" in get(f"/prompts/{pa}/edit").text, "historie promptu: tlačítko smazat verzi")
        check(post(f"/prompts/{pb}/versions/{ver}/delete", {}).status_code == 404, "verzi cizího promptu nejde smazat")
        post(f"/prompts/{pa}/versions/{ver}/delete", {})
        check(app.db("SELECT COUNT(*) FROM prompt_versions WHERE id = ?", ver)[0][0] == 0, "verze promptu smazána")
        post(f"/prompts/{pa}", {"kind": "image", "title": "A2", "prompt": "p1 znovu", "seed": "5", "rating": "4"})
        post(f"/prompts/{pa}/duplicate", {})
        check(app.db("SELECT COUNT(*) FROM prompts WHERE title = 'Kopie: A2' AND is_master = 0")[0][0] == 1, "duplikace promptu")
        post(f"/prompts/{pa}/delete", {})
        check(app.db("SELECT COUNT(*) FROM prompts WHERE id = ?", pa)[0][0] == 0 and app.db("SELECT COUNT(*) FROM prompt_versions WHERE prompt_id = ?", pa)[0][0] == 0, "smazání promptu i s historií")

        # ---------------------------------------------------------------- 5) obrázky
        for fmt, mime in (("jpeg", "image/jpeg"), ("png", "image/png"), ("webp", "image/webp")):
            post(f"/models/{m1}/images", {"alt_text": fmt}, files={"image": (f"x.{fmt}", image_bytes(fmt), mime)})
        imgs = app.db("SELECT id, mime FROM images WHERE model_id = ? ORDER BY id", m1)
        check([m for _, m in imgs] == ["image/jpeg", "image/png", "image/webp"], "upload JPG, PNG, WebP")
        img = imgs[1][0]
        post(f"/models/{m2}/prompts", {"kind": "image", "title": "Cizí", "prompt": "x"})
        foreign = app.db("SELECT id FROM prompts WHERE model_id = ?", m2)[0][0]
        tid = app.db("SELECT MIN(id) FROM ai_tools")[0][0]
        post(f"/images/{imgs[0][0]}", {"alt_text": "a", "is_reference": "1", "prompt_id": str(pb), "tool_id": str(tid), "seed": "42", "notes": "Povedená"})
        check(app.db("SELECT is_reference, prompt_id, tool_id, seed, notes FROM images WHERE id = ?", imgs[0][0]) == [(1, pb, tid, "42", "Povedená")], "obrázek: úprava promptu, nástroje, seedu a poznámky")
        post(f"/images/{imgs[0][0]}", {"alt_text": "a", "prompt_id": str(foreign), "tool_id": "999999"})
        check(app.db("SELECT is_reference, prompt_id, tool_id, seed, notes FROM images WHERE id = ?", imgs[0][0]) == [(0, None, None, None, None)], "obrázek: prompt jiné modelky a neexistující nástroj odmítnuty")
        post(f"/images/{img}", {"is_public": "1", "alt_text": "veřejný"})
        pub = app.db("SELECT public_id FROM images WHERE id = ?", img)[0][0]
        anon = session()
        check(anon.get(app.base + f"/media/p/{pub}.jpg").status_code == 200, "zveřejněný obrázek dostupný")
        post(f"/models/{m1}", {"name": "Žaneta Nováková", "slug": "zaneta-novakova", "status": "active", "persona_age": "28", "page_lang": "cs",
                               "page_published": "1", "avatar_image_id": str(img)})
        check("<img class=\"avatar\"" in anon.get(app.base + "/m/zaneta-novakova").text, "avatar na landing page")
        post(f"/models/{m1}", {"name": "Žaneta Nováková", "slug": "zaneta-novakova", "status": "active", "persona_age": "28", "page_lang": "cs",
                               "page_published": "1", "avatar_image_id": str(imgs[0][0] + 10_000)})
        check(app.db("SELECT avatar_image_id FROM models WHERE id = ?", m1)[0][0] is None, "avatar z cizího/neexistujícího obrázku odmítnut")
        post(f"/images/{img}", {"alt_text": "soukromý"})
        check(anon.get(app.base + f"/media/p/{pub}.jpg").status_code == 404, "znovu soukromý obrázek → 404")
        post(f"/images/{imgs[2][0]}/delete", {})
        check(s.get(A + f"/images/{imgs[2][0]}").status_code == 404, "smazaný obrázek → 404")

        # ---------------------------------------------------------------- 6) účty
        check(has_error(post(f"/models/{m1}/accounts", {"platform_id": "", "handle": "x", "status": "active"}), "Vyber platformu"), "účet: bez platformy")
        r = post(f"/models/{m1}/accounts", {"platform_id": "1", "handle": "@zaneta", "status": "active", "profile_url": "https://www.fanvue.com/zaneta", "show_on_page": "1"})
        acc = int(re.search(r"/accounts/(\d+)$", r.headers["Location"]).group(1))
        check(app.db("SELECT handle, fee_percent, currency FROM accounts WHERE id = ?", acc) == [("zaneta", 20.0, "USD")], "účet: výchozí poplatek a měna z platformy, @ odstraněn")
        get(f"/accounts/{acc}"); get(f"/accounts/{acc}/edit")
        post(f"/accounts/{acc}", {"platform_id": "1", "handle": "zaneta", "status": "active", "fee_percent": "15", "currency": "CZK", "profile_url": "https://www.fanvue.com/zaneta", "show_on_page": "1"})
        check(app.db("SELECT fee_percent, currency FROM accounts WHERE id = ?", acc) == [(15.0, "CZK")], "účet upraven (CZK kvůli testu bez kurzů)")
        r = post(f"/models/{m2}/accounts", {"platform_id": "2", "handle": "of", "status": "planned"})
        acc2 = int(re.search(r"/accounts/(\d+)$", r.headers["Location"]).group(1))
        check("nepovoluje čistě AI persony" in follow(r).text, "varování u OnlyFans")
        check(has_error(post(f"/accounts/{acc2}/delete", {}), "Potvrď smazání"), "smazání účtu vyžaduje potvrzení")
        post(f"/accounts/{acc2}/delete", {"confirm": "1"})
        check(app.db("SELECT COUNT(*) FROM accounts WHERE id = ?", acc2)[0][0] == 0, "účet smazán")
        check("Smazat platformu" not in get("/platforms/1/edit").text, "používaná platforma nemá tlačítko Smazat")
        check(has_error(post("/platforms/1/delete", {}), "Platformu používá"), "platformu s účty nejde smazat")
        check(app.db("SELECT COUNT(*) FROM platforms WHERE id = 1")[0][0] == 1, "platforma s účty zůstala")
        r = post(f"/accounts/{acc}/sync", {})
        check("není připojený" in follow(r).text, "synchronizace nepřipojeného účtu → srozumitelná chyba")
        r = post(f"/accounts/{acc}/fanvue/connect", {})
        check("client_id" in follow(r).text, "připojení bez nastavení Fanvue → návod")

        # ---------------------------------------------------------------- 7) příjmy
        today = subprocess.run(["php", "-r", "echo (new DateTime('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d');"], capture_output=True, text=True).stdout
        month = today[:7]
        post("/earnings", {"account_id": str(acc), "occurred_on": today, "type": "subscription", "gross": "1 000", "fan": "Pavel"})
        post("/earnings", {"account_id": str(acc), "occurred_on": today, "type": "tip", "net": "85", "fan": "Pavel"})
        post("/earnings", {"account_id": str(acc), "occurred_on": today, "type": "message", "gross": "200", "net": "150", "fan": "Eva"})
        post("/earnings", {"account_id": str(acc), "occurred_on": today, "type": "other", "gross": "-100", "note": "refundace"})
        rows = app.db("SELECT type, gross_minor, net_minor FROM transactions ORDER BY id")
        check(rows == [("subscription", 100000, 85000), ("tip", 10000, 8500), ("message", 20000, 15000), ("other", -10000, -8500)], "hrubá/čistá dopočítaná z poplatku 15 %, refundace záporně", str(rows))
        check(has_error(post("/earnings", {"account_id": str(acc), "occurred_on": today, "type": "tip", "gross": "abc"}), "Neplatná částka"), "příjem: neplatná částka")
        check(has_error(post("/earnings", {"account_id": str(acc), "occurred_on": "2026-02-30", "type": "tip", "gross": "1"}), "Datum"), "příjem: neplatné datum")
        check(has_error(post("/earnings", {"account_id": str(acc), "occurred_on": today, "type": "tip"}), "hrubou nebo čistou"), "příjem: chybí částka")
        page = get(f"/earnings?month={month}&type=tip").text
        check("Pavel" in page and "Eva" not in page, "filtr příjmů podle typu")
        get(f"/earnings?month={month}&model={m1}&account={acc}")
        get("/earnings?month=nesmysl")
        tx = app.db("SELECT id FROM transactions WHERE type = 'other'")[0][0]
        post(f"/earnings/{tx}/delete", {})
        check(app.db("SELECT COUNT(*) FROM transactions")[0][0] == 3, "smazání příjmu")
        exp = s.get(A + f"/earnings/export?month={month}")
        rows = list(csv.reader(io.StringIO(exp.content.decode("utf-8-sig")), delimiter=";"))
        check(exp.headers["Content-Type"].startswith("text/csv") and len(rows) == 4 and rows[0][0] == "Datum", "export příjmů: hlavička + 3 řádky")

        sub, pavel = app.db("SELECT id, fan_id FROM transactions WHERE type = 'subscription'")[0]
        check(f'/admin/earnings/{sub}/edit">upravit' in get(f"/earnings?month={month}").text, "seznam příjmů: odkaz upravit")
        form = get(f"/earnings/{sub}/edit").text
        check('value="1000,00"' in form and 'value="850,00"' in form and 'value="Pavel"' in form and "Smazat příjem" in form, "formulář úpravy příjmu předvyplněný")
        base = {"account_id": str(acc), "occurred_on": today, "occurred_time": "08:30", "type": "subscription", "gross": "1200", "net": "", "fan": "Pavel", "note": "upraveno"}
        post(f"/earnings/{sub}", base)
        check(app.db("SELECT gross_minor, net_minor, fan_id, note, source FROM transactions WHERE id = ?", sub) == [(120000, 102000, pavel, "upraveno", "manual")],
              "úprava příjmu: nová částka, dopočet čisté, stejný fanoušek")
        check(app.db("SELECT COUNT(*) FROM transactions")[0][0] == 3, "úprava nevytvoří novou platbu")
        check(has_error(post(f"/earnings/{sub}", dict(base, gross="abc")), "Neplatná částka"), "úprava příjmu: validace")
        check(app.db("SELECT gross_minor FROM transactions WHERE id = ?", sub)[0][0] == 120000, "neplatná úprava nic nezmění")
        post(f"/earnings/{sub}", dict(base, fan="Nový Fan"))
        new_fan = app.db("SELECT fan_id FROM transactions WHERE id = ?", sub)[0][0]
        check(new_fan != pavel and app.db("SELECT display_name FROM fans WHERE id = ?", new_fan) == [("Nový Fan",)], "úprava příjmu: jiný fanoušek")
        post(f"/earnings/{sub}", dict(base, fan=""))
        check(app.db("SELECT fan_id FROM transactions WHERE id = ?", sub)[0][0] is None, "úprava příjmu: fanoušek odebrán")
        post(f"/earnings/{sub}", base)
        check(app.db("SELECT fan_id FROM transactions WHERE id = ?", sub)[0][0] == pavel, "úprava příjmu: zpět na původního fanouška")
        get("/earnings/999999/edit", 404)

        # Platba z Fanvue u připojeného účtu: synchronizace by ruční změnu přepsala → zamčeno; po odpojení jde upravit i smazat.
        app.db("UPDATE accounts SET integration = 'fanvue', credentials_enc = 'x' WHERE id = ?", acc)
        app.db("INSERT INTO transactions (account_id, occurred_at, occurred_on, type, gross_minor, net_minor, currency, fx_rate, gross_czk_minor, net_czk_minor, source, dedupe_key, created_at) "
               "VALUES (?, ? || ' 10:00:00', ?, 'tip', 100, 80, 'CZK', 1, 100, 80, 'fanvue', 'fanvue:test', ? || ' 10:00:00')", acc, today, today, today)
        fv = app.db("SELECT id FROM transactions WHERE source = 'fanvue'")[0][0]
        page = get(f"/earnings?month={month}").text
        check("z API" in page and f"/earnings/{fv}/edit" not in page, "platba z Fanvue: bez odkazu upravit")
        check("synchronizace by změnu přepsala" in follow(s.get(A + f"/earnings/{fv}/edit", allow_redirects=False)).text, "platba z Fanvue: úprava zamčená")
        post(f"/earnings/{fv}", dict(base, gross="1"))
        post(f"/earnings/{fv}/delete", {})
        check(app.db("SELECT gross_minor FROM transactions WHERE id = ?", fv) == [(100,)], "platba z Fanvue: POST úprava i mazání odmítnuty")
        app.db("UPDATE accounts SET integration = 'none', credentials_enc = NULL WHERE id = ?", acc)
        get(f"/earnings/{fv}/edit")
        post(f"/earnings/{fv}/delete", {})
        check(app.db("SELECT COUNT(*) FROM transactions WHERE id = ?", fv)[0][0] == 0, "po odpojení Fanvue jde platbu smazat")

        # ---------------------------------------------------------------- 8) fanoušci
        fans = get("/fans").text
        check(fans.index("Pavel") < fans.index("Eva"), "fanoušci podle útraty (Pavel 935 Kč > Eva 150 Kč)")
        fan = app.db("SELECT id FROM fans WHERE handle = 'Pavel'")[0][0]
        get(f"/fans/{fan}")
        post(f"/fans/{fan}", {"display_name": "Pavel", "handle": "Pavel", "notes": "Má rád hory"})
        check(app.db("SELECT notes FROM fans WHERE id = ?", fan) == [("Má rád hory",)], "poznámka k fanouškovi")
        post(f"/fans/{fan}", {"display_name": "Pavel K.", "handle": "@pavelk", "is_top_spender": "1", "notes": "Má rád hory"})
        check(app.db("SELECT display_name, handle, is_top_spender, external_id FROM fans WHERE id = ?", fan) == [("Pavel K.", "pavelk", 1, "manual:pavel k.")],
              "fanoušek přejmenován, klíč pro párování plateb aktualizován")
        post("/earnings", {"account_id": str(acc), "occurred_on": today, "type": "tip", "gross": "10", "fan": "Pavel K."})
        check(app.db("SELECT fan_id FROM transactions ORDER BY id DESC LIMIT 1")[0][0] == fan, "nová platba se jménem po přejmenování patří stejnému fanouškovi")
        check(has_error(post(f"/fans/{fan}", {"display_name": "Eva", "handle": ""}), "už u účtu existuje"), "fanoušek: duplicitní jméno odmítnuto")
        check(has_error(post(f"/fans/{fan}", {"display_name": "", "handle": ""}), "Vyplň jméno"), "fanoušek: bez jména odmítnut")
        eva = app.db("SELECT id FROM fans WHERE display_name = 'Eva'")[0][0]
        post(f"/fans/{eva}/delete", {})
        check(app.db("SELECT COUNT(*) FROM fans WHERE id = ?", eva)[0][0] == 0 and app.db("SELECT fan_id FROM transactions WHERE type = 'message'") == [(None,)],
              "fanoušek smazán, jeho platby zůstaly")
        get(f"/fans?month={month}&model={m1}")

        # ---------------------------------------------------------------- 9) náklady
        post("/costs", {"incurred_on": today, "category": "hosting", "amount": "299", "currency": "CZK"})
        post("/costs", {"incurred_on": today, "category": "generation", "model_id": str(m1), "amount": "100", "currency": "CZK", "quantity": "50"})
        check(has_error(post("/costs", {"incurred_on": today, "category": "hacked", "amount": "1", "currency": "CZK"}), "Kategorie"), "náklad: podvržená kategorie")
        check(has_error(post("/costs", {"incurred_on": today, "category": "ads", "amount": "-5", "currency": "CZK"}), "nesmí být záporné"), "náklad: záporná částka")
        cost = app.db("SELECT id FROM costs WHERE category = 'hosting'")[0][0]
        get(f"/costs/{cost}/edit")
        post(f"/costs/{cost}", {"incurred_on": today, "category": "hosting", "amount": "399", "currency": "CZK", "note": "VPS"})
        check(app.db("SELECT amount_czk_minor, note FROM costs WHERE id = ?", cost) == [(39900, "VPS")], "náklad upraven")
        check("2,00" in get(f"/models/{m1}").text, "cena za obrázek 100 Kč / 50 ks = 2 Kč")
        get(f"/costs?month={month}&model={m1}")
        check("VPS" in s.get(A + f"/costs/export?month={month}").content.decode("utf-8-sig"), "export nákladů")
        post(f"/costs/{cost}/delete", {})
        check(app.db("SELECT COUNT(*) FROM costs WHERE id = ?", cost)[0][0] == 0, "náklad smazán")

        # ---------------------------------------------------------------- 10) odkazy
        post("/links", {"model_id": str(m1), "label": "TikTok", "source": "tiktok", "target_url": "https://www.fanvue.com/zaneta", "is_active": "1", "show_on_page": "1"})
        code = app.db("SELECT code FROM links WHERE label = 'TikTok'")[0][0]
        check(re.fullmatch(r"[a-z2-9]{7}", code) is not None, "automatický kód odkazu")
        check(has_error(post("/links", {"model_id": str(m1), "label": "X", "source": "x", "code": code, "target_url": "https://x.com/a", "is_active": "1"}), "Kód už používá"), "odkaz: duplicitní kód")
        check(has_error(post("/links", {"model_id": str(m1), "label": "X", "source": "x", "code": "a b", "target_url": "https://x.com/a"}), "Kód: 3–40"), "odkaz: neplatný kód")
        link = app.db("SELECT id FROM links WHERE code = ?", code)[0][0]
        check(anon.get(app.base + f"/go/{code}", allow_redirects=False, headers={"User-Agent": "Mozilla/5.0"}).status_code == 302, "odkaz přesměrovává")
        post(f"/links/{link}", {"model_id": str(m1), "label": "TikTok", "source": "tiktok", "code": code, "target_url": "https://www.fanvue.com/zaneta"})
        check(anon.get(app.base + f"/go/{code}", allow_redirects=False).status_code == 404, "vypnutý odkaz → 404")
        post(f"/links/{link}/delete", {})
        check(app.db("SELECT COUNT(*) FROM links")[0][0] == 0, "odkaz smazán")

        # ---------------------------------------------------------------- 11) import CSV
        r = post("/earnings/import", {"account_id": str(acc)}, files={"csv": ("export.xlsx", b"PK\x03\x04", "application/octet-stream")})
        check("musí být CSV" in follow(r).text, "import: ne-CSV soubor odmítnut")
        data = "Datum;Částka;Typ\n05.01.2026 10:00;1 234,50;Tip\n"
        r = post("/earnings/import", {"account_id": str(acc)}, files={"csv": ("a.csv", data.encode(), "text/csv")})
        token = re.search(r"token=([a-f0-9]{32})", r.headers["Location"]).group(1)
        r = post("/earnings/import/confirm", {"token": token, "map_gross": "1", "timezone": "UTC"})
        check("Vyber sloupec s datem" in follow(r).text, "import: bez sloupce s datem → chyba")
        r = post("/earnings/import/confirm", {"token": token, "map_date": "0", "map_gross": "1", "map_type": "2", "timezone": "UTC"})
        check(app.db("SELECT gross_minor, net_minor, type FROM transactions WHERE source = 'csv'") == [(123450, 104933, "tip")], "import: české číslo, typ, dopočet čisté částky")
        r = post("/earnings/import/confirm", {"token": token, "map_date": "0", "map_gross": "1", "timezone": "UTC"})
        check("vypršel" in follow(r).text, "import: token po dokončení neplatí")

        # ---------------------------------------------------------------- 12) nastavení a přehled
        post("/settings/goal", {"goal": "20 000", "goal_basis": "net"})
        page = get("").text
        check("z cíle 20" in page and "příjmy po poplatcích" in page, "nový cíl a způsob výpočtu na přehledu")
        check(has_error(post("/settings/goal", {"goal": "abc", "goal_basis": "net"}), "Neplatná částka"), "cíl: neplatná částka")
        check(has_error(post("/settings/password", {"current_password": "spatne", "password": "Nove-Heslo-1234", "password_confirm": "Nove-Heslo-1234"}), "Současné heslo nesouhlasí"), "heslo: špatné současné")
        check(has_error(post("/settings/password", {"current_password": "E2E-Test-Heslo-2026!", "password": "kratke", "password_confirm": "kratke"}), "alespoň 12"), "heslo: příliš krátké")
        check(has_error(post("/settings/password", {"current_password": "E2E-Test-Heslo-2026!", "password": "Nove-Heslo-1234", "password_confirm": "Jine-Heslo-1234"}), "neshodují"), "heslo: neshoda")
        check(has_error(post("/settings/username", {"username": "novy", "current_password": "spatne"}), "Současné heslo nesouhlasí"), "jméno: špatné heslo")
        check(has_error(post("/settings/username", {"username": "a b", "current_password": "E2E-Test-Heslo-2026!"}), "3–50 znaků"), "jméno: neplatné znaky")
        post("/settings/username", {"username": "majitel", "current_password": "E2E-Test-Heslo-2026!"})
        check(app.db("SELECT username FROM users") == [("majitel",)], "přihlašovací jméno změněno")
        login(app, user="majitel")
        check("majitel" in get("/settings").text, "přihlášení novým jménem, stávající relace platí")
        post("/settings/username", {"username": "tester", "current_password": "E2E-Test-Heslo-2026!"})
        post("/settings/2fa/start", {})
        check(has_error(post("/settings/2fa/enable", {"code": "123456"}), "Kód nesouhlasí"), "2FA: špatný kód")
        check(app.db("SELECT totp_enabled FROM users")[0][0] == 0, "2FA zůstalo vypnuté")
        get("?month=2020-01"); get("?month=abc")
        check("Přehled · 2020-01" in s.get(A + "?month=2020-01").text, "přehled starého měsíce")

        # ---------------------------------------------------------------- 13) chybové stránky
        get("/neexistuje", 404)
        check("Zpět do administrace" in s.get(A + "/neexistuje").text, "404 s odkazem zpět")
        get("/models/999999", 404)
        check(s.get(A + "/logout").status_code == 405, "GET na POST akci → 405")

        # ---------------------------------------------------------------- 14) crawler všech odkazů
        seen, queue, bad = set(), deque([A]), []
        while queue and len(seen) < 400:
            url = queue.popleft()
            if url in seen:
                continue
            seen.add(url)
            r = s.get(url, allow_redirects=False)
            ctype = r.headers.get("Content-Type", "")
            if r.status_code != 200 or ("text/html" in ctype and any(x in r.text for x in ("Fatal error", "Warning:", "Něco se pokazilo"))):
                bad.append((url, r.status_code))
                continue
            if "text/html" not in ctype:
                continue
            for href in re.findall(r'(?:href|src)="([^"#]+)"', r.text):
                target = urljoin(url, href.replace("&amp;", "&"))
                if urlparse(target).netloc == urlparse(A).netloc and urlparse(target).path.startswith("/admin") and "logout" not in target:
                    queue.append(target)
        check(not bad and len(seen) > 40, f"crawler: {len(seen)} adres v administraci bez chyby", str(bad[:5]))
        for path in ["/m/zaneta-novakova", "/sitemap.xml", "/robots.txt"]:
            check(anon.get(app.base + path).status_code == 200, f"veřejná {path}")

        # ---------------------------------------------------------------- 15) smazání modelky
        uploads = lambda: len(os.listdir(os.path.join(app.dir, "storage", "uploads")))
        before = uploads()
        check(has_error(post(f"/models/{m1}/delete", {"confirm_name": "spatne"}), "napiš přesně jméno"), "smazání modelky vyžaduje jméno")
        post(f"/models/{m1}/delete", {"confirm_name": "Žaneta Nováková"})
        check(app.db("SELECT COUNT(*) FROM models WHERE id = ?", m1)[0][0] == 0, "modelka smazána")
        check(app.db("SELECT COUNT(*) FROM transactions")[0][0] == 0 and app.db("SELECT COUNT(*) FROM accounts WHERE model_id = ?", m1)[0][0] == 0, "smazány i její účty a příjmy")
        check(uploads() == before - 2, "smazány i soubory obrázků", f"{before} → {uploads()}")
        check(app.db("SELECT COUNT(*) FROM costs WHERE model_id IS NULL")[0][0] >= 1, "náklady zůstaly jako společné")
        check(anon.get(app.base + "/m/zaneta-novakova").status_code == 404, "landing page smazané modelky → 404")

    return check.summary()


if __name__ == "__main__":
    sys.exit(main())
