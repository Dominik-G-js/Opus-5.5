---
name: ai-model-naming
description: Vymyslí a ověří jméno a handle pro novou AI modelku (virtuální tvůrkyni pro Fanvue, X, TikTok, Instagram, Reddit). Návrhy podle niky a publika, vyřazení jmen skutečných osob a značek, kontrola volných handlů a domény, výstup připravený do AI Model Studia. Použij, když Dominik chce jméno, přezdívku, handle nebo doménu pro AI modelku.
---

# Jméno pro AI modelku

Cíl: 3–5 jmen, která jsou zapamatovatelná, dohledatelná, nepatří žádné skutečné osobě ani značce a mají stejný volný handle na všech sítích. Vymyslet jméno je snadné, důležité je **ověření** — dřívější návrhy („Luna Vale“, „Ivy Noir“, „Sofia Reyes“) už používaly skutečné osoby, proto bez ověření nic nedoporučuj.

## 1. Vstupy

Zjisti (co chybí, doptej se jednou otázkou, jinak zvol rozumný výchozí stav a napiš jaký):

- **nika** (fitness, gothic, cosplay, girl-next-door, luxury, gamer…),
- **vzhled a povaha** (případně z profilu modelky nebo `docs/03-modelky-koncepty-a-prompty.md`),
- **publikum**: jazyk a země (výchozí: anglicky mluvící, US/UK),
- **tón**: sladká / tajemná / sportovní / elegantní / hravá,
- jména **existujících modelek** — nové jméno se od nich musí zřetelně lišit (v aplikaci: Modelky).

## 2. Pravidla pro jméno

- Formát **křestní jméno + příjmení/přezdívka**, dohromady 2 slova. Křestní jméno ideálně 4–7 písmen.
- Snadná výslovnost v angličtině i češtině, jednoznačný pravopis po vyslovení (žádné „Kaytlynn“).
- Bez diakritiky a bez znaků, které se v handlu ztratí.
- **Handle ≤ 15 znaků** — tolik povoluje X (nejpřísnější). TikTok dovolí 24, Instagram 30, takže 15 projde všude.
- Unikátní ve vyhledávání: přesná fráze `"Jméno Příjmení"` nesmí vracet konkrétní osobu.
- **Zakázáno:** jména a přezdívky známých osob, modelek, influencerek a postav z filmů/her; ochranné známky a názvy značek; cokoli, co naznačuje nezletilost (teen, girl, baby, lil, school, petite v kombinaci s mladým věkem apod.). Persona je vždy dospělá (v aplikaci min. 21 let) a nesmí se podobat reálné osobě.

## 3. Návrhy

Vygeneruj **20 návrhů** ve 4 stylech (po 5): klasický, moderní/krátký, s příjmením podle niky nebo nálady, s netradiční ale čitelnou kombinací. U každého jedna věta, proč sedí k nice. Z nich vyber **8 nejlepších** k ověření.

## 4. Ověření (u každého z 8)

Každý výsledek označ: ✓ ověřeno volné · ✗ obsazené/kolize · ? nešlo ověřit. **Nikdy netvrď „volné“, pokud jsi to skutečně neověřil.**

1. **Skutečná osoba nebo značka** — webové vyhledávání:
   `"Jméno Příjmení"`, `"Jméno Příjmení" model`, `"Jméno Příjmení" onlyfans OR fanvue OR instagram`, `"Jméno Příjmení" trademark`.
   Známá osoba, tvůrkyně s podobným obsahem nebo značka → vyřadit (✗).
2. **Handle** — sestav varianty v pořadí: `jmenoprijmeni`, `jmeno.prijmeni`, `jmeno_prijmeni`, `jmenoprijmeni` + krátká přípona (`x`, `ai`, `real`…). Zkontroluj profily:
   - Fanvue: `https://www.fanvue.com/<handle>`
   - X: `https://x.com/<handle>`
   - TikTok: `https://www.tiktok.com/@<handle>`
   - Instagram: `https://www.instagram.com/<handle>/`
   - Reddit: `https://www.reddit.com/user/<handle>`

   Sítě často blokují automatické načtení nebo vrací přihlašovací stránku — pak **?** a dej Dominikovi odkazy k ruční kontrole (otevřít v anonymním okně: „stránka neexistuje“ = volné).
3. **Doména `.com`** — RDAP registru Verisign: `https://rdap.verisign.com/com/v1/domain/<jmeno>.com`
   HTTP **404 = není registrovaná** (lze koupit), **200 = obsazená**. Když je `.com` obsazená, zkus `<jmeno>official.com` nebo `<jmeno>.ai`/`.me` (u jiných koncovek RDAP přes `https://rdap.org/domain/<domena>`).

## 5. Výstup

Tabulka 3–5 nejlepších (seřazeno od nejlepšího):

| Jméno | Proč sedí | Handle | Fanvue | X | TikTok | IG | Reddit | Doména |
|---|---|---|---|---|---|---|---|---|

Pod ni pro vítěze **pole do AI Model Studia** (formulář „Přidat AI modelku“):

- **Jméno:** …
- **URL slug:** jen `a-z`, `0-9` a pomlčky, např. `jmeno-prijmeni`
- **Slogan** (max. 200 znaků): …
- **SEO titulek** (max. 70 znaků): např. `Jméno Příjmení — AI creator | <nika>`
- **SEO popis** (max. 170 znaků): …
- **Vlastní doména** (pokud volná): …

Na konec: co zůstalo neověřené (?) a doporučení **zaregistrovat handle na všech sítích a doménu hned ve stejný den**, než je někdo zabere. Připomeň, že bio má uvádět, že jde o AI personu (pravidla platforem a EU AI Act — viz `docs/01-platformy-a-pravidla.md`).
