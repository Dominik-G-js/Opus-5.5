-- Výchozí data: platformy, AI nástroje a nastavení.
-- Poplatky a ceny ověřeny rešerší 09/2026 (zdroje v docs/). Vše lze v aplikaci upravit.

INSERT INTO settings (key, value) VALUES
    ('monthly_goal_czk_minor', '1000000'),
    ('goal_basis', 'profit');

INSERT INTO platforms (name, role, ai_policy, default_fee_percent, default_currency, url, notes) VALUES
    ('Fanvue', 'monetization', 'allowed', 20, 'USD', 'https://www.fanvue.com',
     'AI modelky povoleny s jasným označením AI. Poplatek 15 % prvních 12 měsíců, pak 20 %. Oficiální API (OAuth).'),
    ('OnlyFans', 'monetization', 'banned', 20, 'USD', 'https://onlyfans.com',
     'Čistě AI persona zakázána: obsah musí zobrazovat ověřeného držitele účtu. Bez oficiálního API.'),
    ('Fansly', 'monetization', 'restricted', 20, 'USD', 'https://fansly.com',
     'Od 06/2025 zakázán fotorealistický AI obsah napodobující reálné lidi. Bez oficiálního API.'),
    ('Patreon', 'monetization', 'unknown', 10, 'USD', 'https://www.patreon.com',
     'Jen SFW (bez explicitního obsahu). 10 % pro nové tvůrce od 08/2025.'),
    ('X (Twitter)', 'traffic', 'allowed', 0, 'USD', 'https://x.com',
     'NSFW povoleno jen s označením citlivého obsahu; AI obsah musí mít AI označení. Odkazy v příspěvku snižují dosah.'),
    ('TikTok', 'traffic', 'restricted', 0, 'USD', 'https://www.tiktok.com',
     'Jen SFW. Povinný AI štítek. Zakázáno přesměrovávat na prémiový sexuální obsah.'),
    ('Instagram', 'traffic', 'restricted', 0, 'USD', 'https://www.instagram.com',
     'Od 31. 8. 2026 štítek „AI-generated profile“; bez štítku omezený dosah. Adult odkazy blokuje.'),
    ('Threads', 'traffic', 'restricted', 0, 'USD', 'https://www.threads.net',
     'Pravidla Meta (AI info štítek), adult odkazy rizikové.'),
    ('Reddit', 'traffic', 'restricted', 0, 'USD', 'https://www.reddit.com',
     'Globálně AI nezakázáno, ale pravidla určuje každý subreddit. Nutná karma a stáří účtu.');

INSERT INTO ai_tools (name, category, pricing_model, url, monthly_price_minor, currency, notes, created_at) VALUES
    ('fal.ai – Flux LoRA trénink', 'lora_training', 'per_use', 'https://fal.ai/models/fal-ai/flux-lora-portrait-trainer', NULL, 'USD',
     '$0.0024/krok, min. 1000 kroků (~$2.40). Fast training ~$2/běh.', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('fal.ai – Flux LoRA generování', 'image', 'per_use', 'https://fal.ai/models/fal-ai/flux-lora', NULL, 'USD',
     '$0.035/megapixel (~$0.037 za 1024×1024).', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('Higgsfield (Soul ID)', 'image', 'subscription', 'https://higgsfield.ai', NULL, 'USD',
     'Konzistentní postava z 20+ fotek. Plány cca $19–129/měs podle kreditů.', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('Nano Banana Pro (Gemini API)', 'image', 'per_use', 'https://ai.google.dev', NULL, 'USD',
     '$0.134 za 1K/2K obrázek, $0.24 za 4K. Jen SFW.', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('Seedream 4.5', 'image', 'per_use', 'https://www.byteplus.com', NULL, 'USD',
     '~$0.04/obrázek (BytePlus). Jen SFW.', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('GPT Image 2', 'image', 'per_use', 'https://platform.openai.com', NULL, 'USD',
     '1024×1024: $0.006 low / $0.053 medium / $0.211 high. Jen SFW.', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('ComfyUI na RunPod (RTX 4090)', 'image', 'per_use', 'https://www.runpod.io', NULL, 'USD',
     'Vlastní workflow bez cloudového filtru. Community Cloud ~$0.34/h.', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('Kling 3.0', 'video', 'per_use', 'https://klingai.com', NULL, 'USD',
     'Image-to-video ~$0.084–0.10/s.', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('Wan 2.6', 'video', 'per_use', 'https://fal.ai', NULL, 'USD',
     'Nejlevnější video ~$0.05/s, 1080p.', strftime('%Y-%m-%d %H:%M:%S', 'now')),
    ('ElevenLabs', 'voice', 'subscription', 'https://elevenlabs.io', 600, 'USD',
     'Starter $6/měs (instant voice clone), Creator $22/měs.', strftime('%Y-%m-%d %H:%M:%S', 'now'));
