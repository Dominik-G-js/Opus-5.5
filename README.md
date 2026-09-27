# AI Model Studio

Soukromý systém pro správu AI modelek: kolik která vydělala, kdo z fanoušků kolik utratil, co stála výroba, jaké AI nástroje a prompty používáš (aby modelka vypadala pořád stejně), a veřejná SEO landing page pro každou modelku.

Strategie, rešerše a plán jsou v `docs/`:

1. [Platformy, pravidla a právo](docs/01-platformy-a-pravidla.md) — kde AI modelky smí vydělávat (Fanvue ano, OnlyFans ne), pravidla TikToku / X / Instagramu / Redditu, AI Act, daně
2. [Marketing a SEO](docs/02-marketing-a-seo.md) — trychtýř, postup po platformách, cenotvorba, SEO
3. [Modelky, nástroje a prompty](docs/03-modelky-koncepty-a-prompty.md) — konzistentní tvář (LoRA), ceny nástrojů, šablony promptů, 5 konceptů
4. [Plán na 10 000 Kč měsíčně](docs/04-plan-10000-kc.md) — počty, 90denní plán, kontrolní body

## Co systém umí

| Oblast | Funkce |
|---|---|
| **Přehled** | měsíční cíl (výchozí 10 000 Kč zisku) s odhadem do konce měsíce, příjmy vs. náklady za 12 měsíců, zisk a ROI podle modelek, platforem a typů plateb, top fanoušci, prokliky podle zdroje |
| **Modelky** | profil a character bible (vzhled, povaha, příběh), technika (základní model, LoRA, trigger, seed), použité AI nástroje, tisknutelná „Character bible“ |
| **Prompty** | knihovna promptů podle typu, master prompt předvyplněný z character bible, hodnocení, **historie verzí s obnovením** |
| **Obrázky** | soukromé úložiště (originály i s metadaty), referenční fotky, zveřejnění na landing page jako **očištěná kopie bez metadat** (prompty z ComfyUI neuniknou) |
| **Příjmy** | ruční zadání, **import CSV** z libovolné platformy (mapování sloupců, bez duplicit), **Fanvue API** (automaticky), přepočet na CZK **kurzem ČNB ke dni platby**, export CSV pro účetní |
| **Fanoušci** | kdo kolik utratil (za měsíc i celkem), rozpad podle typu plateb, poznámky pro chat |
| **Náklady** | podle modelky, nástroje a kategorie, počet kusů → **cena za obrázek**, společné náklady, export CSV |
| **Odkazy** | sledovací odkazy `/go/kód` pro každý zdroj (TikTok, X, Reddit…), prokliky po dnech bez cookies a bez ukládání IP |
| **Landing page** | `/m/slug` nebo vlastní doména, SEO (title, description, canonical, Open Graph, ProfilePage + Person JSON-LD se `sameAs`), sitemap.xml, robots.txt, SFW galerie, 18+ odkazy za potvrzením věku, povinné označení AI |

### Napojení na platformy
- **Fanvue** — oficiální API (OAuth 2.0 + PKCE). Stahuje každou platbu (typ, částka, fanoušek) a denní počty nových/zrušených předplatitelů. Endpointy ověřené z oficiálních balíčků `@fanvue/builder-sdk` a `@fanvue/n8n-nodes-fanvue`.
- **OnlyFans** — nemá oficiální API; neoficiální služby porušují podmínky OF a hrozí ban. Navíc OF čistě AI modelky zakazuje. Proto žádné napojení — případně ruční zadání / CSV.
- **Ostatní** (Fansly, Patreon…) — ruční zadání nebo CSV import.

## Požadavky
- PHP **8.2+** s rozšířeními `pdo_sqlite`, `sodium`, `curl`, `mbstring`, `gd`, `fileinfo` (volitelně `intl` pro hezčí slugy)
- Apache (mod_rewrite) nebo nginx, HTTPS
- Žádný Composer, žádné externí závislosti. Databáze je SQLite v jednom souboru.

## Instalace

```bash
git clone <repo> ai-model-studio && cd ai-model-studio
php bin/console install
```

Průvodce se zeptá na adresu (např. `https://studio.domafix.cz`), cestu k administraci a vytvoří:
- `config/config.php` s náhodným šifrovacím klíčem (práva 0600, **necommitovat**),
- složky `storage/`, databázi a admin účet.

Instaluj pod stejným uživatelem, pod kterým běží PHP na webu (např. `sudo -u www-data php bin/console install`), jinak web nebude moct zapisovat do `storage/`.

Po přihlášení **zapni v Nastavení 2FA**.

### Nasazení — VPS s nginx
Document root musí být složka `public/` — zbytek projektu (config, storage, src) pak z webu není vidět.

```nginx
server {
    listen 443 ssl http2;
    server_name studio.example.com nessawren.com;   # admin doména + vlastní domény modelek
    root /var/www/ai-model-studio/public;
    client_max_body_size 16m;

    location / { try_files $uri /index.php$is_args$args; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

### Nasazení — Apache / sdílený hosting
- Ideálně nastav document root na `public/` (součástí je `public/.htaccess`).
- Pokud to hosting neumí, nahraj celý projekt do webrootu: kořenový `.htaccess` přesměruje vše do `public/` a zakáže přístup ke `config/`, `storage/`, `src/` atd. Ověř, že `https://domena/config/config.php` a `https://domena/storage/database.sqlite` vrací 403.
- V PHP nastav `upload_max_filesize = 16M` a `post_max_size = 20M`.

### Vlastní doména modelky
V profilu modelky vyplň doménu (např. `nessawren.com`) a nasměruj ji (DNS + vhost) na **stejnou** složku `public/`. Systém ji pozná podle hlavičky Host a na `/` zobrazí landing page. `www.` se přesměruje na doménu bez www. Administrace na cizích doménách není dostupná.

### Cron
```cron
# Fanvue synchronizace každou hodinu
0 * * * *  cd /var/www/ai-model-studio && php bin/console sync >> storage/logs/cron.log 2>&1
# Úklid (staré pokusy o přihlášení, dočasné soubory)
30 3 * * * cd /var/www/ai-model-studio && php bin/console cleanup >> storage/logs/cron.log 2>&1
```

### Fanvue API
1. Přečti si [Fanvue API Access & Usage Policy](https://legal.fanvue.com/api-policy).
2. Ve Fanvue Builderu / developer portálu (návod: [api.fanvue.com/docs → Authentication → Quick Start](https://api.fanvue.com/docs/authentication/quick-start)) vytvoř **off-platform** aplikaci:
   - Redirect URI: `https://TVOJE-ADRESA/ADMIN-CESTA/integrations/fanvue/callback` (přesnou adresu ukazuje *Nastavení* v systému),
   - scopes: `openid offline_access offline read:self read:insights read:fan`.
3. Client ID a Client Secret vlož do `config/config.php` (`fanvue.client_id`, `fanvue.client_secret`).
4. V systému: modelka → účet Fanvue → **Připojit Fanvue** → **Synchronizovat teď**. Dál už to jede z cronu.

Fanvue vrací částky v centech; měnu účtu nastav v systému (výchozí USD).

## Zabezpečení
- Přihlášení: hesla Argon2id, **2FA (TOTP)**, omezení pokusů (5 / 15 min na IP+účet, 20 / h na IP, 50 / h na účet), stejná odezva pro neexistující účet, nová session po přihlášení.
- Session: HttpOnly, Secure, SameSite=Lax, odhlášení po 2 h nečinnosti a max. po 12 h.
- Každý formulář má **CSRF token** a kontroluje se hlavička Origin.
- Hlavičky: přísná Content-Security-Policy (bez inline skriptů), X-Frame-Options, nosniff, HSTS, Referrer-Policy, `noindex` a `no-store` pro administraci.
- Administrace jen přes HTTPS a jen na hlavní doméně; `/` hlavní domény vrací 404 (cesta k administraci se neprozrazuje, ani v robots.txt).
- Tokeny Fanvue a 2FA tajemství jsou v DB **šifrované** (libsodium) klíčem z configu.
- Nahrávání: jen JPG/PNG/WebP ověřené podle obsahu, náhodné názvy, úložiště mimo webroot; zveřejněné kopie bez metadat.
- CSV export je chráněný proti formula injection; všechny SQL dotazy jsou parametrizované, veškerý výstup escapovaný.
- Za Cloudflare/proxy doplň jejich IP rozsahy do `trusted_proxies`, jinak omezení pokusů podle IP nebude fungovat správně.
- Ztráta telefonu s 2FA: `php bin/console user:2fa-reset jmeno`.

## Zálohy
Zálohuj `storage/` (databáze, obrázky) a `config/config.php` (bez `app_key` nepůjdou dešifrovat tokeny). Konzistentní kopie databáze za běhu:
```bash
sqlite3 storage/database.sqlite ".backup storage/backup-$(date +%F).sqlite"
```
LoRA soubory modelek zálohuj zvlášť (min. 2 místa) — bez nich modelku znovu nevytvoříš.

## Aktualizace
```bash
git pull && php bin/console migrate
```

## Vývoj a testy
```bash
php bin/console install --base-url=http://127.0.0.1:8000 --admin-path=/admin
php -S 127.0.0.1:8000 -t public bin/dev-router.php
php tests/run.php                                   # unit testy (bez závislostí)
AMS_PASSWORD='…' python3 tests/e2e/smoke.py         # průchod celou aplikací — jen proti vývojové instanci!
```

## Struktura
```
public/        webroot (index.php, CSS, JS, .htaccess)
src/           aplikace (Kernel, Security, Service, Integration/Fanvue, Controller)
templates/     šablony (administrace + veřejná landing page)
migrations/    schéma databáze a výchozí data (platformy, AI nástroje s cenami)
bin/console    instalace, uživatelé, synchronizace, úklid
storage/       databáze, obrázky, logy (mimo web)
tests/         testy (bez závislostí)
docs/          rešerše a strategie
```

## Omezení
- Jeden tým / jeden provoz: všichni přihlášení uživatelé vidí všechno (bez rolí).
- OnlyFans a Fansly nemají API → ruční zadání nebo CSV.
- Kurzy ČNB: pokud je ČNB nedostupná, systém nechá zadat kurz ručně (nic nepočítá odhadem).
- Změna hesla neodhlásí jiná zařízení s běžící session (vyprší nejpozději za 12 h).
