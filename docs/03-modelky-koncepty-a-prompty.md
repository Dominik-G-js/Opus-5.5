# 3. Modelky: koncepty, nástroje, konzistentní tvář a prompty

## Pravidla, která platí pro každou modelku

1. **Jednoznačně dospělá** — věk postavy 21+ (systém nic nižšího nepustí), dospělé proporce a styling. Do negativního promptu vždy `child, teen, young-looking`.
2. **Nikdy se nepodobá reálné osobě** — žádná celebrita, influencerka ani známá tvář. Tvář vždy vznikne od nuly, ne podle fotky člověka.
3. **Vždy označená jako AI** (bio, landing page, štítky platforem) — viz [docs/01](01-platformy-a-pravidla.md).
4. **Jasná nika a osobnost** — obecná „fitness holka“ podle zdrojů neprodává; prodává konkrétní postava s povahou. ([OFGenerator — niche selection](https://www.ofgenerator.com/blog/ai-persona-niche-selection-2026))

Doporučení: **začni jednou modelkou**. Druhou přidej, až první stabilně plní cíl — obsah, chat i marketing jedné postavy dají práci na každý den.

## Nástroje a ceny (ověřeno 09/2026)

| Krok | Nástroj | Cena | Poznámka |
|---|---|---|---|
| Návrh tváře, SFW fotky, úpravy | Nano Banana Pro (Gemini) | $0.134 / obrázek 1K–2K | výborné detaily pleti a editace s referencí ([PixMind](https://www.pixmind.io/posts/nano-banana-pro-pricing-guide-2026)) |
| | Seedream 4.5 | ~$0.04 / obrázek | levný, fotorealistický ([OpenRouter](https://openrouter.ai/bytedance-seed/seedream-4.5)) |
| | GPT Image 2 | $0.006–0.211 podle kvality | aktuálně nejvýš v žebříčcích realismu ([AIReiter](https://aireiter.com/blog/gpt-image-2-api-pricing), [OpenArt](https://openart.ai/blog/best-ai-image-generators-for-realistic-photos/)) |
| Trénink LoRA (konzistentní tvář) | fal.ai Flux LoRA trainer | ~$2–2.40 / trénink | 20–30 fotek, ~20 min ([fal.ai](https://fal.ai/models/fal-ai/flux-lora-portrait-trainer)) |
| Generování s LoRA | fal.ai Flux LoRA | ~$0.037 / 1024×1024 | ([fal.ai](https://fal.ai/models/fal-ai/flux-lora)) |
| Vlastní workflow (ComfyUI) | RunPod RTX 4090 | ~$0.34 / h | plná kontrola, platíš jen čas ([RunPod](https://www.runpod.io/gpu-models/rtx-4090), [SynpixCloud](https://www.synpixcloud.com/blog/rtx-4090-cloud-rental-worth-it)) |
| Vše v jednom (tvář + video) | Higgsfield Soul ID | cca $19–129 / měs. | Soul ID drží tvář z 20+ fotek ([Creatify — ceník](https://creatify.ai/blog/higgsfield-pricing-(2026)-plans-and-what-you-ll-actually-pay), [Higgsfield — Soul ID](https://higgsfield.ai/blog/sould-id-best-character-consistency)) |
| Video (TikTok, Reels) | Wan 2.6 | ~$0.05 / s | nejlevnější 1080p ([Fluxnote](https://fluxnote.io/guides/ai-video-model-pricing-comparison-2026)) |
| | Kling 3.0 | ~$0.084–0.10 / s | lepší pohyb postavy ([BuildMVPFast](https://www.buildmvpfast.com/api-costs/ai-video)) |
| Hlasové zprávy | ElevenLabs | Starter $6 / měs., Creator $22 / měs. | vlastní hlas modelky ([BIGVU](https://bigvu.tv/blog/elevenlabs-pricing-2026-plans-credits-commercial-rights-api-costs/)) |

Všechny nástroje jsou v systému předvyplněné (*AI nástroje*). Náklady zapisuj **i s počtem kusů** → systém spočítá cenu za obrázek a celkovou cenu výroby modelky.

Pozor: velká cloudová API (Nano Banana, Seedream, GPT Image) generují jen SFW. Pro obsah pro dospělé potřebuješ nástroje a modely, jejichž licence a podmínky to dovolují (např. vlastní workflow v ComfyUI) — tam platí stejná pravidla výše, jen je hlídáš sám.

## Postup: jak mít vždy stejnou modelku

Nejspolehlivější je kombinace postupů, ne jeden trik: LoRA + reference obličeje + kontrola kvality. ([Make-Influencer — 6 metod](https://make-influencer.ai/guides/ai-influencer-face-consistency), [Higgsfield — průvodce](https://higgsfield.ai/blog/how-to-create-ai-influencer), [Stack Sheriff — ComfyUI Flux + LoRA](https://stacksheriff.com/ai-tools/comfyui-flux-character/))

1. **Character bible** — v systému vyplň vzhled (obličej, vlasy, oči, pleť, postava, poznávací znaky, styl). Popisy piš konkrétně a měř je slovy, která se neplete (např. „small mole above left lip“). Tohle je jediný zdroj pravdy.
2. **Hero face** — vygeneruj 30–50 portrétů podle bible (Nano Banana Pro / Seedream / GPT Image), vyber jednu tvář. Ulož ji v systému jako *referenční*.
3. **Character sheet** — ze stejné tváře pomocí editace s referencí vytvoř: zepředu (5–6×), ze tří čtvrtin zleva (3–4×), zprava (3–4×), z profilu, různé výrazy, světla a outfity. ([IImagined — LoRA dataset](https://iimagined.ai/blog/lora-training-influencer-perfect-face-consistency))
4. **Dataset** — vyber 20–30 nejkonzistentnějších fotek, ořízni na 1024×1024, popisky s unikátním trigger slovem (např. `ohwx_nessa`, aby se nekřížilo se slovy, která model zná). ([Flux LoRA Noob's Guide](https://thefluxtrain.com/blog/noobs-guide-to-flux-lora-training/))
5. **Trénink LoRA** (~$2–3, ~20 min). Soubor LoRA si zálohuj — bez něj modelku znovu nevytvoříš. Umístění zapiš do profilu modelky.
6. **Generování** — vždy trigger + master popis postavy, síla LoRA 0.6–0.9 (start 0.8). Měníš jen scénu, oblečení, světlo, pózu. ([Stack Sheriff](https://stacksheriff.com/ai-tools/comfyui-flux-character/))
7. **Kontrola kvality** — FaceDetailer / oprava obličeje, ruce, upscale. Nekonzistentní výsledky nepublikuj.
8. **Video** — z nejlepších fotek image-to-video (Kling / Wan), 5–8 s.
9. **Všechno zapiš** do systému: prompt, seed, nastavení, hodnocení. Povedené prompty označ 4–5★ → objeví se v *Character bible* (tisk do PDF).

## Šablony promptů

Struktura, která funguje pro fotorealismus: **postava → scéna → oblečení → světlo → foťák/objektiv → realismus**. Realistická pleť potřebuje texturu („visible pores“), přirozené nedokonalosti a do negativu „plastic, airbrushed, waxy“. Candid fotky z mobilu působí věrohodněji než „studio, magazine“. ([Quest Studio](https://queststudio.io/blog/make-it-look-real-prompt-rules), [AI Video Bootcamp](https://aivideobootcamp.com/blog/photorealistic-ai-prompts-guide-2026/))

**Master prompt (kostra):**
```
{trigger}, photo of a {věk}-year-old woman, {obličej}, {oči}, {vlasy}, {pleť}, {postava}, {znaky},
{SCÉNA}, wearing {OUTFIT}, {SVĚTLO},
shot on iPhone 15 Pro, 24mm, candid, natural skin texture, visible pores, subtle imperfections, slight film grain
```

**Negativní prompt (základ):**
```
plastic skin, airbrushed, waxy, doll-like, cgi, 3d render, cartoon, oversaturated, deformed hands, extra fingers,
bad anatomy, blurry face, watermark, text, logo, child, teen, young-looking
```

**Scény pro SFW obsah (TikTok, Instagram, landing page):**

| Pilíř | Scéna | Světlo |
|---|---|---|
| Ráno | sitting on a messy bed with a coffee mug, morning routine | soft window light, warm tones |
| Město | walking on a cobblestone street in the old town, looking back over shoulder | golden hour, backlit hair |
| Fitness | mirror selfie in a modern gym, holding phone | overhead gym lights, slight flash reflection |
| Kavárna | at a café table by the window with a laptop and croissant | overcast daylight |
| Večer | on a balcony with city lights behind, wrapped in a blanket | neon and warm lamp mixed light |

Video prompt (image-to-video): `she slowly turns her head toward the camera and smiles, hair moves slightly in the breeze, handheld phone camera, natural motion, 5 seconds`

Když v systému kliknete na **„+ Master prompt postavy“**, prompt se předvyplní z character bible.

## Pět konceptů modelek

Všechny koncepty jsou vymyšlené, nevycházejí z reálné osoby. Jména jsem volil tak, aby nekolidovala se známými osobnostmi, ale než jméno použiješ, ověř na Googlu a sítích, že ho nikdo nepoužívá (a že nepatří reálné známé osobě).

### 1. Nessa Wren — cozy gamerka „girl next door“ (26)
- **Nika:** hry, útulné outfity, noční streamy, kočka. „Girl next door“ patří k nejlépe vydělávajícím typům person. ([OFGenerator](https://www.ofgenerator.com/blog/how-much-do-ai-creators-make-fanvue-2026))
- **Vzhled:** soft oval face, light freckles across nose and cheeks, bright green eyes, long wavy copper-auburn hair with curtain bangs, fair skin, slim athletic build, small mole above left lip.
- **Styl:** oversized hoodies, cat-ear headset, knee socks, pastelový gaming setup s RGB.
- **Povaha:** vtipná, trochu stydlivá, sarkastická u her, píše malými písmeny, emoji 🎮☕.
- **TikTok:** „rate my setup“, reakce na prohru, „outfit na stream“. **X:** memes z her + teasery.
- **Master prompt:** `ohwx_nessa, photo of a 26-year-old woman, soft oval face, light freckles across nose and cheeks, bright green eyes, long wavy copper-auburn hair with curtain bangs, fair skin, slim athletic build, small mole above left lip, sitting in a gaming chair in a cozy bedroom with RGB lights, wearing an oversized lavender hoodie and a cat-ear headset, soft monitor glow mixed with warm lamp light, shot on iPhone 15 Pro, candid, natural skin texture, visible pores`

### 2. Mila Vesna — česká fitness a hory (27)
- **Nika:** fitness, túry (Krkonoše, Tatry), sauna, zdravé jídlo. Evropská/česká identita je pro zahraniční publikum zajímavá a přirozeně odlišuje.
- **Vzhled:** heart-shaped face, high cheekbones, blue-grey eyes, honey-blonde hair in a high ponytail, light sun-kissed skin, athletic toned build, small tattoo of a mountain on inner wrist.
- **Styl:** sportovní sety, outdoor bundy, sauna ručník, přírodní tóny.
- **Povaha:** energická, motivační, přímá, humor o „východoevropské“ povaze.
- **TikTok:** „rok v horách“, workout splity, co jím za den. **X:** fitness progres + teasery.
- **Master prompt:** `ohwx_mila, photo of a 27-year-old woman, heart-shaped face, high cheekbones, blue-grey eyes, honey-blonde hair in a high ponytail, light sun-kissed skin, athletic toned build, small mountain tattoo on inner wrist, on a rocky mountain trail above the clouds, wearing a fitted black sports set and a light windbreaker, bright morning sun, shot on iPhone 15 Pro, candid, natural skin texture`

### 3. Odile Graves — alt / goth umělkyně (25)
- **Nika:** alternativní styl, tetování, horory, vinyly, kresba. Alt publikum je věrné a na X silné.
- **Vzhled:** angular face, sharp jawline, dark brown eyes with winged eyeliner, straight jet-black hair with blunt bangs, pale porcelain skin, slim build, black floral tattoo sleeve on right arm, septum piercing.
- **Styl:** černá, síťovina, korzety, boty na platformě, stříbrné šperky.
- **Povaha:** suchý humor, tajemná, ale vřelá k fanouškům, doporučuje filmy a hudbu.
- **TikTok:** „rate my tattoo idea“, kreslení, horor doporučení. **X:** estetika, noční fotky, teasery.
- **Master prompt:** `ohwx_odile, photo of a 25-year-old woman, angular face, sharp jawline, dark brown eyes with winged eyeliner, straight jet-black hair with blunt bangs, pale porcelain skin, slim build, black floral tattoo sleeve on right arm, septum piercing, in a dim record store browsing vinyl, wearing a black mesh top and a leather jacket, moody neon light, shot on iPhone 15 Pro, candid, natural skin texture`

### 4. Catalina Brisa — latinskoamerická cestovatelka (28)
- **Nika:** cestování, pláže, tanec, vaření, dvojjazyčný obsah (EN/ES = dvojnásobné publikum).
- **Vzhled:** oval face, full lips, warm brown eyes, long dark-brown voluminous curly hair, golden tan skin, curvy build, small beauty mark on right cheekbone.
- **Styl:** letní šaty, plavky na pláž, zlaté šperky, výrazné barvy.
- **Povaha:** temperamentní, veselá, hodně emoji, oslovuje fanoušky „mi amor“.
- **TikTok:** „3 dny v …“, recepty, taneční trendy. **X:** cestovní fotky + teasery.
- **Master prompt:** `ohwx_catalina, photo of a 28-year-old woman, oval face, full lips, warm brown eyes, long voluminous dark-brown curly hair, golden tan skin, curvy build, small beauty mark on right cheekbone, walking on a sunny beach boardwalk, wearing a flowy white summer dress and gold hoop earrings, golden hour sunlight, shot on iPhone 15 Pro, candid, natural skin texture`

### 5. Elena Marlowe — elegantní „confident woman“ (34)
- **Nika:** móda, víno, wellness, sebevědomí. Starší publikum s vyšší kupní silou; věk postavy zároveň vylučuje jakoukoli pochybnost o dospělosti.
- **Vzhled:** elegant oval face, defined cheekbones, hazel eyes, shoulder-length dark chestnut hair in soft waves, olive skin with fine natural lines, tall slender-curvy build, thin gold necklace.
- **Styl:** saténové košile, kostýmky, večerní šaty, minimalistické šperky.
- **Povaha:** sebejistá, klidná, laskavě dominantní, píše celými větami.
- **TikTok/Reels:** „outfit do práce“, víno a rady, „10 věcí, co jsem pochopila po třicítce“. **X:** elegantní teasery.
- **Master prompt:** `ohwx_elena, photo of a 34-year-old woman, elegant oval face, defined cheekbones, hazel eyes, shoulder-length dark chestnut hair in soft waves, olive skin with fine natural lines, tall slender build, thin gold necklace, sitting at a hotel bar with a glass of red wine, wearing a champagne satin blouse, warm dim ambient light, shot on a full-frame camera, 50mm, shallow depth of field, natural skin texture`

## Kontrolní seznam před spuštěním modelky
- [ ] Character bible vyplněná, master prompt uložený a ohodnocený
- [ ] LoRA natrénovaná a zálohovaná (2 místa)
- [ ] 60–100 fotek a 15–20 videí do zásoby
- [ ] Jméno a handle ověřené, stejné všude
- [ ] Fanvue účet jako AI creator, ověřená totožnost, AI v biu
- [ ] TikTok / Instagram s AI štítky, X a Reddit účty zahřáté
- [ ] Landing page zveřejněná, doména v Search Console
- [ ] Sledovací odkaz pro každý zdroj
