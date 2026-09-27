"""End-to-end průchod celou aplikací proti běžícímu VÝVOJOVÉMU serveru.

POZOR: zapisuje testovací data (modelku, platby, 2FA). Nikdy nespouštěj proti produkci.

Příprava (čistá databáze):
    rm -f storage/database.sqlite*
    AMS_PASSWORD='Velmi-Tajne-Heslo-2026!' php bin/console install --base-url=http://127.0.0.1:8000 --admin-path=/admin --username=dominik
    php -S 127.0.0.1:8000 -t public bin/dev-router.php
Spuštění:
    AMS_PASSWORD='Velmi-Tajne-Heslo-2026!' python3 tests/e2e/smoke.py      (vyžaduje pip install requests)
"""
import base64, hashlib, hmac, os, re, struct, subprocess, sys, time
import requests

B = os.environ.get("AMS_BASE", "http://127.0.0.1:8000")
A = B + os.environ.get("AMS_ADMIN", "/admin")
USER = os.environ.get("AMS_USER", "dominik")
PASSWORD = os.environ["AMS_PASSWORD"]
s = requests.Session()
s.trust_env = False  # nepoužívat proxy pro localhost
fails = []

def csrf(html):
    m = re.search(r'name="_csrf" value="([a-f0-9]+)"', html)
    assert m, "missing csrf"
    return m.group(1)

def get(path, expect=200):
    r = s.get(A + path, allow_redirects=False)
    check(r.status_code == expect, f"GET {path} -> {r.status_code} (expected {expect})")
    for bad in ("Fatal error", "Warning:", "Deprecated:", "Notice:", "Něco se pokazilo"):
        check(bad not in r.text, f"GET {path} contains {bad!r}")
    return r

def post(path, data, files=None, expect=303):
    page = s.get(A + "/settings")
    data = dict(data, _csrf=csrf(page.text))
    r = s.post(A + path, data=data, files=files, allow_redirects=False)
    check(r.status_code == expect, f"POST {path} -> {r.status_code} (expected {expect}) {r.text[:300] if r.status_code >= 400 else ''}")
    return r

def check(cond, msg):
    if not cond:
        fails.append(msg)
        print("FAIL:", msg)

def totp(secret, t=None):
    key = base64.b32decode(secret + "=" * (-len(secret) % 8))
    counter = struct.pack(">Q", int((t or time.time()) // 30))
    h = hmac.new(key, counter, hashlib.sha1).digest()
    o = h[19] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)

def flashes(r_html):
    return re.findall(r'class="flash flash-(\w+)"[^>]*>([^<]*)', r_html)

# Přihlášení
r = s.get(A + "/login")
r = s.post(A + "/login", data={"_csrf": csrf(r.text), "username": USER, "password": PASSWORD}, allow_redirects=False)
check(r.status_code == 303 and r.headers["Location"].endswith(A[len(B):]), "login")

for p in ["", "/models", "/models/new", "/earnings", "/earnings/new", "/earnings/import", "/fans", "/costs", "/costs/new",
          "/links", "/links/new", "/tools", "/tools/new", "/platforms", "/platforms/new", "/settings"]:
    get(p)

# Validace: věk pod 21 a prázdné jméno
r = post("/models", {"name": "", "status": "concept", "persona_age": "19", "page_lang": "en"})
check(r.headers["Location"].endswith("/models/new"), "validation redirect back")
r = get("/models/new")
check("Věk postavy: zadej celé číslo 21–99" in r.text, "age validation message shown")

# Vytvoření modelky
r = post("/models", {
    "name": "Nessa Wren", "slug": "", "status": "building", "persona_age": "24", "page_lang": "en",
    "niche": "Cozy gamer girl next door", "tagline": "Cozy gamer, coffee addict, night owl.",
    "public_bio": "Virtual AI creator. Gaming, cozy outfits and late-night chats.\nAll content is AI-generated.",
    "look_face": "soft oval face, small straight nose, full lips, light freckles across nose",
    "look_hair": "long wavy copper-auburn hair, curtain bangs", "look_eyes": "green eyes",
    "lora_trigger": "ohwx_luna", "lora_weight": "0.8", "base_model": "FLUX.1 dev",
    "default_negative": "plastic skin, airbrushed, waxy, extra fingers",
    "page_published": "1", "seo_title": "Luna Vale — AI creator | Fanvue, X & TikTok",
    "seo_description": "Luna Vale is an AI-generated virtual creator. Cozy gaming, outfits and exclusive content.",
})
loc = r.headers["Location"]
model_id = int(re.search(r"/models/(\d+)$", loc).group(1))
r = get(f"/models/{model_id}")
check("Nessa Wren" in r.text and "master prompt" in r.text.lower(), "model page")

# Duplicitní slug
r = post("/models", {"name": "Nessa Wren", "status": "concept", "persona_age": "25", "page_lang": "en"})
r = get("/models/new")
check("URL slug už používá jiná modelka" in r.text, "duplicate slug rejected")

# Master prompt + úprava → historie
r = get(f"/models/{model_id}/prompts/new?kind=character_base")
check("ohwx_luna, photo of a 24-year-old woman" in r.text, "master prompt prefilled")
r = post(f"/models/{model_id}/prompts", {"kind": "character_base", "title": "Master — Luna", "prompt": "ohwx_luna, photo of a 24-year-old woman, copper hair", "is_master": "1", "rating": "5", "seed": "1234"})
prompt_id = int(re.search(r"/prompts/(\d+)/edit", r.headers["Location"]).group(1))
r = post(f"/prompts/{prompt_id}", {"kind": "character_base", "title": "Master — Luna", "prompt": "ohwx_luna, photo of a 24-year-old woman, copper hair, freckles", "is_master": "1", "rating": "5", "seed": "1234"})
r = get(f"/prompts/{prompt_id}/edit")
check("Obnovit tuto verzi" in r.text, "prompt version history")
ver = int(re.search(r"/restore/(\d+)", r.text).group(1))
post(f"/prompts/{prompt_id}/restore/{ver}", {})
r = get(f"/prompts/{prompt_id}/edit")
check(len(re.findall(r"/restore/\d+", r.text)) == 2, "restore creates snapshot")
post(f"/prompts/{prompt_id}/duplicate", {})
get(f"/models/{model_id}/bible")

# Obrázek: vygenerovat PNG s metadaty (tEXt „prompt“) a nahrát
png = subprocess.run(["php", "-r", '$i=imagecreatetruecolor(800,1000);imagefill($i,0,0,imagecolorallocate($i,200,120,90));ob_start();imagepng($i);$d=ob_get_clean();'
                      '$chunk="prompt\\0SECRET-WORKFLOW";$c="tEXt".$chunk;$crc=pack("N",crc32($c));$t=pack("N",strlen($chunk)).$c.$crc;'
                      '$d=substr($d,0,33).$t.substr($d,33);echo $d;'], capture_output=True).stdout
check(b"SECRET-WORKFLOW" in png, "test png contains metadata")
r = post(f"/models/{model_id}/images", {"is_reference": "1", "alt_text": "Luna portrait", "prompt_id": str(prompt_id)}, files={"image": ("luna.png", png, "image/png")})
r = get(f"/models/{model_id}")
image_id = int(re.search(r"/images/(\d+)\"", r.text).group(1))
r = s.get(A + f"/images/{image_id}")
check(r.status_code == 200 and r.headers["Content-Type"] == "image/png", "private image served to admin")
# Neplatný soubor
r = post(f"/models/{model_id}/images", {}, files={"image": ("evil.php.png", b"<?php echo 1; ?>", "image/png")})
r = get(f"/models/{model_id}")
check("Povolené formáty jsou JPG, PNG a WebP" in r.text, "non-image upload rejected")

# Zveřejnit + avatar + publikovat
post(f"/images/{image_id}", {"is_public": "1", "is_reference": "1", "alt_text": "Luna portrait"})
edit = get(f"/models/{model_id}/edit").text
form = {"name": "Nessa Wren", "slug": "nessa-wren", "status": "active", "persona_age": "24", "page_lang": "en", "page_published": "1",
        "tagline": "Cozy gamer, coffee addict, night owl.", "public_bio": "Virtual AI creator.\nAll content is AI-generated.",
        "seo_title": "Luna Vale — AI creator", "avatar_image_id": str(image_id)}
post(f"/models/{model_id}", form)

# Účet Fanvue + ruční příjem v CZK + v USD (ČNB nedostupné → chyba s výzvou k ručnímu kurzu)
r = post(f"/models/{model_id}/accounts", {"platform_id": "1", "handle": "@lunavale", "profile_url": "https://www.fanvue.com/lunavale", "status": "active", "show_on_page": "1"})
account_id = int(re.search(r"/accounts/(\d+)$", r.headers["Location"]).group(1))
r = get(f"/accounts/{account_id}")
check("Připojit Fanvue" in r.text or "client_id" in r.text, "fanvue section")
r = post(f"/models/{model_id}/accounts", {"platform_id": "2", "handle": "lunaof", "status": "planned"})
r = s.get(B + r.headers["Location"])
check("nepovoluje čistě AI persony" in r.text, "OnlyFans warning flash")

today = time.strftime("%Y-%m-%d")
post("/earnings", {"account_id": str(account_id), "occurred_on": today, "occurred_time": "10:30", "type": "tip", "gross": "500", "currency": "CZK", "fan": "BigSpender77"})
post("/earnings", {"account_id": str(account_id), "occurred_on": today, "occurred_time": "11:00", "type": "subscription", "gross": "1 000,00", "currency": "CZK", "fan": "BigSpender77"})
post("/earnings", {"account_id": str(account_id), "occurred_on": today, "type": "message", "net": "12.50", "currency": "USD", "fx_rate": "21,15", "fan": "night_owl"})
r = post("/earnings", {"account_id": str(account_id), "occurred_on": "2026-09-01", "type": "tip", "gross": "10", "currency": "USD"})
check(r.headers["Location"].endswith("/earnings/new"), "USD without rate -> back to form (ČNB blocked in sandbox)")
r = get("/earnings/new")
check("ČNB" in r.text, "ČNB unavailable message shown")

r = get(f"/earnings?month={today[:7]}")
check("BigSpender77" in r.text and "night_owl" in r.text, "earnings list")
# net: 500 gross, fee 20% -> 400 CZK; 1000 -> 800; 12.50 USD net * 21.15 = 264.375 -> 264.38
r = get("")
check("1 464 Kč" in r.text, "dashboard net total 1464 Kč")

# Náklady
post("/costs", {"incurred_on": today, "category": "training", "model_id": str(model_id), "tool_id": "1", "amount": "150", "currency": "CZK"})
post("/costs", {"incurred_on": today, "category": "generation", "model_id": str(model_id), "tool_id": "2", "amount": "200", "currency": "CZK", "quantity": "100"})
post("/costs", {"incurred_on": today, "category": "hosting", "amount": "99", "currency": "CZK"})
r = get(f"/models/{model_id}")
check("2,00 Kč" in r.text, "cost per image 2 Kč")
r = get("")
check("1 015 Kč" in r.text, "profit = 1464 - 449 = 1015 Kč")

# Fanoušci
r = get("/fans")
check(r.text.index("BigSpender77") < r.text.index("night_owl"), "fans sorted by spend")
fan_id = int(re.search(r"/fans/(\d+)\"", r.text).group(1))
get(f"/fans/{fan_id}")
post(f"/fans/{fan_id}", {"notes": "Likes cozy content"})

# Odkazy + prokliky
post("/links", {"model_id": str(model_id), "label": "TikTok", "source": "tiktok", "code": "luna-tt", "target_url": "https://x.com/lunavale", "is_active": "1", "show_on_page": "1"})
post("/links", {"model_id": str(model_id), "label": "Fanvue", "source": "landing", "code": "luna-fv", "target_url": "https://www.fanvue.com/lunavale", "is_active": "1", "show_on_page": "1", "is_premium": "1"})
r = post("/links", {"model_id": str(model_id), "label": "Bad", "source": "x", "target_url": "javascript:alert(1)", "is_active": "1"})
check(r.headers["Location"].endswith("/links/new"), "javascript: url rejected")
anon = requests.Session(); anon.trust_env = False
for ua in ["Mozilla/5.0 (iPhone)", "Mozilla/5.0 (iPhone)", "Googlebot/2.1"]:
    r = anon.get(B + "/go/luna-tt", headers={"User-Agent": ua}, allow_redirects=False)
    check(r.status_code == 302 and r.headers["Location"] == "https://x.com/lunavale", "go redirect")
r = get("/links")
check(re.search(r'<td class="num">2</td>\s*<td class="num">2</td>', r.text) is not None, "2 human clicks counted, bot ignored")

# Veřejná stránka
r = anon.get(B + "/m/nessa-wren")
check(r.status_code == 200, "public page 200")
check("AI-generated virtual creator" in r.text and '"@type":"ProfilePage"' in r.text, "public page disclosure + JSON-LD")
check("https://www.fanvue.com/lunavale" in r.text, "sameAs includes profile")
check("set-cookie" not in {k.lower() for k in r.headers}, "public page sets no cookies")
nonce = re.search(r"'nonce-([^']+)'", r.headers["Content-Security-Policy"]).group(1)
check(f'nonce="{nonce}"' in r.text, "CSP nonce matches inline style")
media = re.search(r'/media/p/([a-f0-9]{32})\.jpg', r.text).group(0)
r = anon.get(B + media)
check(r.status_code == 200 and r.headers["Content-Type"] == "image/jpeg", "public media served")
check(b"SECRET-WORKFLOW" not in r.content, "metadata stripped from public image")
r = anon.get(B + "/sitemap.xml")
check("/m/nessa-wren" in r.text, "sitemap lists model")
r = anon.get(A + f"/images/{image_id}", allow_redirects=False)
check(r.status_code == 303, "private image requires login")

# Změna hesla odhlásí ostatní zařízení, aktuální session zůstane
other = requests.Session(); other.trust_env = False
r = other.get(A + "/login")
other.post(A + "/login", data={"_csrf": csrf(r.text), "username": USER, "password": PASSWORD}, allow_redirects=False)
check(other.get(A, allow_redirects=False).status_code == 200, "druhé zařízení přihlášené")
NEW_PASSWORD = PASSWORD + "-nove"
post("/settings/password", {"current_password": PASSWORD, "password": NEW_PASSWORD, "password_confirm": NEW_PASSWORD})
check(other.get(A, allow_redirects=False).status_code == 303, "po změně hesla je druhé zařízení odhlášené")
check(s.get(A, allow_redirects=False).status_code == 200, "aktuální session po změně hesla platí")
post("/settings/password", {"current_password": NEW_PASSWORD, "password": PASSWORD, "password_confirm": PASSWORD})

# 2FA
post("/settings/2fa/start", {})
r = get("/settings")
secret = re.search(r'<code class="mono">([A-Z2-7 ]+)</code>', r.text).group(1).replace(" ", "")
post("/settings/2fa/enable", {"code": "000000"})
r = get("/settings")
check("Kód nesouhlasí" in r.text, "wrong totp rejected")
post("/settings/2fa/enable", {"code": totp(secret)})
r = get("/settings")
check("zapnuto" in r.text, "2fa enabled")

# Odhlášení a přihlášení s 2FA
post("/logout", {})
r = s.get(A, allow_redirects=False)
check(r.status_code == 303, "logged out")
r = s.get(A + "/login")
r = s.post(A + "/login", data={"_csrf": csrf(r.text), "username": USER, "password": PASSWORD}, allow_redirects=False)
check(r.headers["Location"].endswith("/login/2fa"), "2fa step")
r = s.get(A, allow_redirects=False)
check(r.status_code == 303, "2fa pending is not logged in")
r = s.get(A + "/login/2fa")
time.sleep(0)
code = totp(secret, time.time() + 30)  # další krok (předchozí už byl použit při zapnutí)
r = s.post(A + "/login/2fa", data={"_csrf": csrf(r.text), "code": code}, allow_redirects=False)
check(r.headers.get("Location", "").endswith(A[len(B):]), "2fa login ok")
get("")

# CSV import (CZK, aby nebyl potřeba kurz ČNB)
csv_data = "Date;Type;Gross;Net;Fan;Currency\n2026-09-02 10:00:00;Subscription;200,00;160,00;csv_fan;CZK\n2026-09-03 11:00:00;Tip;100,00;80,00;csv_fan;CZK\nbad-date;Tip;1;1;x;CZK\n"
r = post("/earnings/import", {"account_id": str(account_id)}, files={"csv": ("export.csv", csv_data.encode(), "text/csv")})
token = re.search(r"token=([a-f0-9]{32})", r.headers["Location"]).group(1)
r = get(f"/earnings/import?token={token}")
check("csv_fan" in r.text, "csv preview")
mapping = {"token": token, "map_date": "0", "map_type": "1", "map_gross": "2", "map_net": "3", "map_fan": "4", "map_currency": "5", "timezone": "UTC"}
r = post("/earnings/import/confirm", mapping)
r2 = s.get(B + r.headers["Location"])
check("Importováno 2 plateb" in r2.text and "Řádek 4" in r2.text, "csv import result " + str(flashes(r2.text)))
# Znovu stejný soubor → duplicity
r = post("/earnings/import", {"account_id": str(account_id)}, files={"csv": ("export.csv", csv_data.encode(), "text/csv")})
token = re.search(r"token=([a-f0-9]{32})", r.headers["Location"]).group(1)
mapping["token"] = token
r = post("/earnings/import/confirm", mapping)
r2 = s.get(B + r.headers["Location"])
check("Importováno 0 plateb, přeskočeno 2 duplicit" in r2.text, "csv reimport dedupe " + str(flashes(r2.text)))

# Export pro účetní
r = s.get(A + f"/earnings/export?month={today[:7]}")
check(r.status_code == 200 and r.headers["Content-Type"].startswith("text/csv") and "BigSpender77" in r.text, "earnings export")
r = s.get(A + f"/costs/export?month={today[:7]}")
check(r.status_code == 200 and "Trénink LoRA" in r.text, "costs export")

# Smazání modelky s chybným potvrzením
r = post(f"/models/{model_id}/delete", {"confirm_name": "nope"})
check(r.headers["Location"].endswith("/edit"), "delete needs confirmation")

print("\n" + ("ALL OK" if not fails else f"{len(fails)} FAILURES"))
sys.exit(1 if fails else 0)
