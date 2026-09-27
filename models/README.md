# Modelky jako soubory

Každý soubor `*.json` v této složce je kompletní AI modelka připravená k importu: profil a vzhled, AI nástroje, prompty, účty na platformách a sledovací odkazy.

| Soubor | Modelka |
|---|---|
| [`tia-tempest.json`](tia-tempest.json) | Tia Tempest — 25 let, blond, „sunshine with a chance of storms“ (girl-next-door + GFE) |

## Import

- **Administrace:** Modelky → **Importovat ze souboru** → vyber soubor → Importovat.
- **Server (konzole):** `php bin/console model:import models/tia-tempest.json`

Každá položka se kontroluje stejnými pravidly jako formuláře v administraci. Když je v souboru chyba, **neuloží se nic** a vypíšou se všechny chyby najednou. Opakovaný import stejné modelky skončí chybou „URL slug už používá jiná modelka“ — pro kopii změň `name`, `slug` a `code` u odkazů.

Po importu je modelka ve stavu *Ve výrobě*, účty *Plánované*, sledovací odkazy vypnuté a landing page skrytá. Co udělat dál, je v poli **Poznámky** na profilu modelky.

## Formát (verze 1)

```jsonc
{
  "format": "ai-model-studio/model",
  "version": 1,
  "model":    { … },   // profil — pole jako ve formuláři „Přidat AI modelku“
  "tools":    [ … ],   // AI nástroje přiřazené modelce
  "prompts":  [ … ],   // knihovna promptů
  "accounts": [ … ],   // účty na platformách
  "links":    [ … ]    // sledovací odkazy
}
```

Pravidla pro hodnoty:

- Text, číslo nebo `true`/`false` (zaškrtávací pole). Delší text lze zapsat jako **pole řádků** `["řádek 1", "řádek 2"]` — spojí se novým řádkem.
- Pole začínající podtržítkem (`"_poznamka"`) jsou komentáře a ignorují se. Na neznámá pole (překlep) import upozorní.
- Nástroje a platformy se hledají **podle názvu** v aplikaci (bez ohledu na velikost písmen). Neznámý nástroj nebo platforma se přeskočí s upozorněním.

### `model`

`name`, `slug`, `status` (`concept` · `building` · `active` · `paused` · `retired`), `persona_age` (min. 21), `page_lang` (`en` · `cs`), `page_published`, `page_domain`, `niche`, `tagline`, `public_bio`, `backstory`, `personality`, `look_face`, `look_hair`, `look_eyes`, `look_body`, `look_skin`, `look_marks`, `look_style`, `base_model`, `lora_name`, `lora_trigger`, `lora_weight`, `lora_location`, `default_seed`, `default_negative`, `seo_title`, `seo_description`, `notes`.

Pole `look_*` pište anglicky jako části promptu — aplikace z nich skládá master prompt postavy.

### `tools`

`{ "name": "fal.ai – Flux LoRA trénink", "purpose": "trénink LoRA" }`

### `prompts`

`kind` (`character_base` · `image` · `video` · `negative` · `caption` · `chat_persona` · `dataset` · `other`), `title`, `prompt`, `negative_prompt`, `seed`, `settings`, `tool` (název nástroje), `is_master` (jen jeden na typ), `rating` (1–5), `notes`.

### `accounts`

`platform` (název, např. `Fanvue`, `X (Twitter)`), `handle`, `profile_url`, `status` (`planned` · `warming` · `active` · `paused` · `banned`), `currency`, `fee_percent`, `show_on_page`, `sync_since`, `notes`. Bez `currency` a `fee_percent` se použijí výchozí hodnoty platformy.

### `links`

`label`, `source` (`tiktok` · `x` · `instagram` · `threads` · `reddit` · `telegram` · `youtube` · `landing` · `other`), `target_url`, `code` (3–40 znaků; prázdný = náhodný), `is_active`, `is_premium` (18+ sekce landing page), `show_on_page`, `sort_order`.

V `target_url` lze použít zástupný text `{landing_url}` — nahradí se adresou landing page modelky (vlastní doména, nebo `/m/{slug}`).
