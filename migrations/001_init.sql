-- Základní schéma AI Model Studio.
-- Časy (…_at) jsou v UTC ve formátu 'Y-m-d H:i:s'. Data (…_on) jsou lokální data (Europe/Prague).
-- Peníze jsou v nejmenších jednotkách měny (centy, haléře) jako INTEGER.

CREATE TABLE users (
    id              INTEGER PRIMARY KEY,
    username        TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    password_hash   TEXT    NOT NULL,
    totp_secret_enc TEXT,
    totp_enabled    INTEGER NOT NULL DEFAULT 0,
    totp_last_step  INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT    NOT NULL,
    last_login_at   TEXT
);

CREATE TABLE login_attempts (
    id         INTEGER PRIMARY KEY,
    ip         TEXT    NOT NULL,
    username   TEXT    NOT NULL,
    success    INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX idx_login_attempts_ip ON login_attempts (ip, created_at);
CREATE INDEX idx_login_attempts_user ON login_attempts (username, created_at);

CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE ai_tools (
    id            INTEGER PRIMARY KEY,
    name          TEXT NOT NULL UNIQUE COLLATE NOCASE,
    category      TEXT NOT NULL,
    pricing_model TEXT NOT NULL,
    url           TEXT,
    monthly_price_minor INTEGER,
    currency      TEXT NOT NULL DEFAULT 'USD',
    notes         TEXT,
    created_at    TEXT NOT NULL
);

CREATE TABLE models (
    id                  INTEGER PRIMARY KEY,
    name                TEXT    NOT NULL,
    slug                TEXT    NOT NULL UNIQUE,
    status              TEXT    NOT NULL DEFAULT 'concept',
    persona_age         INTEGER NOT NULL CHECK (persona_age >= 18),
    niche               TEXT,
    tagline             TEXT,
    public_bio          TEXT,
    backstory           TEXT,
    personality         TEXT,
    look_face           TEXT,
    look_hair           TEXT,
    look_eyes           TEXT,
    look_body           TEXT,
    look_skin           TEXT,
    look_marks          TEXT,
    look_style          TEXT,
    base_model          TEXT,
    lora_name           TEXT,
    lora_trigger        TEXT,
    lora_weight         TEXT,
    lora_location       TEXT,
    default_seed        TEXT,
    default_negative    TEXT,
    page_published      INTEGER NOT NULL DEFAULT 0,
    page_lang           TEXT    NOT NULL DEFAULT 'en',
    page_domain         TEXT UNIQUE,
    seo_title           TEXT,
    seo_description     TEXT,
    avatar_image_id     INTEGER,
    notes               TEXT,
    created_at          TEXT    NOT NULL,
    updated_at          TEXT    NOT NULL
);

CREATE TABLE model_tools (
    model_id INTEGER NOT NULL REFERENCES models (id) ON DELETE CASCADE,
    tool_id  INTEGER NOT NULL REFERENCES ai_tools (id) ON DELETE CASCADE,
    purpose  TEXT,
    PRIMARY KEY (model_id, tool_id)
);

CREATE TABLE prompts (
    id              INTEGER PRIMARY KEY,
    model_id        INTEGER NOT NULL REFERENCES models (id) ON DELETE CASCADE,
    tool_id         INTEGER REFERENCES ai_tools (id) ON DELETE SET NULL,
    kind            TEXT    NOT NULL,
    title           TEXT    NOT NULL,
    prompt          TEXT    NOT NULL,
    negative_prompt TEXT,
    seed            TEXT,
    settings        TEXT,
    is_master       INTEGER NOT NULL DEFAULT 0,
    rating          INTEGER CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
    notes           TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
CREATE INDEX idx_prompts_model ON prompts (model_id, kind);

CREATE TABLE prompt_versions (
    id              INTEGER PRIMARY KEY,
    prompt_id       INTEGER NOT NULL REFERENCES prompts (id) ON DELETE CASCADE,
    prompt          TEXT    NOT NULL,
    negative_prompt TEXT,
    seed            TEXT,
    settings        TEXT,
    created_at      TEXT    NOT NULL
);
CREATE INDEX idx_prompt_versions_prompt ON prompt_versions (prompt_id);

CREATE TABLE images (
    id            INTEGER PRIMARY KEY,
    model_id      INTEGER NOT NULL REFERENCES models (id) ON DELETE CASCADE,
    prompt_id     INTEGER REFERENCES prompts (id) ON DELETE SET NULL,
    tool_id       INTEGER REFERENCES ai_tools (id) ON DELETE SET NULL,
    stored_name   TEXT    NOT NULL UNIQUE,
    original_name TEXT    NOT NULL,
    mime          TEXT    NOT NULL,
    width         INTEGER NOT NULL,
    height        INTEGER NOT NULL,
    size_bytes    INTEGER NOT NULL,
    sha256        TEXT    NOT NULL,
    is_reference  INTEGER NOT NULL DEFAULT 0,
    is_public     INTEGER NOT NULL DEFAULT 0,
    public_id     TEXT UNIQUE,
    alt_text      TEXT,
    seed          TEXT,
    notes         TEXT,
    created_at    TEXT    NOT NULL
);
CREATE INDEX idx_images_model ON images (model_id);

CREATE TABLE platforms (
    id                  INTEGER PRIMARY KEY,
    name                TEXT NOT NULL UNIQUE COLLATE NOCASE,
    role                TEXT NOT NULL,
    ai_policy           TEXT NOT NULL DEFAULT 'unknown',
    default_fee_percent REAL NOT NULL DEFAULT 0,
    default_currency    TEXT NOT NULL DEFAULT 'USD',
    url                 TEXT,
    notes               TEXT
);

CREATE TABLE accounts (
    id               INTEGER PRIMARY KEY,
    model_id         INTEGER NOT NULL REFERENCES models (id) ON DELETE CASCADE,
    platform_id      INTEGER NOT NULL REFERENCES platforms (id) ON DELETE RESTRICT,
    handle           TEXT    NOT NULL,
    profile_url      TEXT,
    status           TEXT    NOT NULL DEFAULT 'active',
    currency         TEXT    NOT NULL DEFAULT 'USD',
    fee_percent      REAL    NOT NULL DEFAULT 0,
    show_on_page     INTEGER NOT NULL DEFAULT 0,
    integration      TEXT    NOT NULL DEFAULT 'none',
    external_uuid    TEXT,
    credentials_enc  TEXT,
    sync_since       TEXT,
    last_synced_at   TEXT,
    last_sync_error  TEXT,
    notes            TEXT,
    created_at       TEXT    NOT NULL
);
CREATE INDEX idx_accounts_model ON accounts (model_id);

CREATE TABLE fans (
    id               INTEGER PRIMARY KEY,
    account_id       INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    external_id      TEXT    NOT NULL,
    handle           TEXT,
    display_name     TEXT,
    is_top_spender   INTEGER NOT NULL DEFAULT 0,
    first_seen_at    TEXT    NOT NULL,
    notes            TEXT,
    UNIQUE (account_id, external_id)
);

CREATE TABLE transactions (
    id              INTEGER PRIMARY KEY,
    account_id      INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    fan_id          INTEGER REFERENCES fans (id) ON DELETE SET NULL,
    occurred_at     TEXT    NOT NULL,
    occurred_on     TEXT    NOT NULL,
    type            TEXT    NOT NULL,
    gross_minor     INTEGER NOT NULL,
    net_minor       INTEGER NOT NULL,
    currency        TEXT    NOT NULL,
    fx_rate         REAL    NOT NULL,
    gross_czk_minor INTEGER NOT NULL,
    net_czk_minor   INTEGER NOT NULL,
    source          TEXT    NOT NULL,
    dedupe_key      TEXT UNIQUE,
    note            TEXT,
    created_at      TEXT    NOT NULL
);
CREATE INDEX idx_transactions_account_on ON transactions (account_id, occurred_on);
CREATE INDEX idx_transactions_on ON transactions (occurred_on);
CREATE INDEX idx_transactions_fan ON transactions (fan_id);

CREATE TABLE costs (
    id              INTEGER PRIMARY KEY,
    model_id        INTEGER REFERENCES models (id) ON DELETE SET NULL,
    tool_id         INTEGER REFERENCES ai_tools (id) ON DELETE SET NULL,
    category        TEXT    NOT NULL,
    incurred_on     TEXT    NOT NULL,
    amount_minor    INTEGER NOT NULL,
    currency        TEXT    NOT NULL,
    fx_rate         REAL    NOT NULL,
    amount_czk_minor INTEGER NOT NULL,
    quantity        INTEGER,
    note            TEXT,
    created_at      TEXT    NOT NULL
);
CREATE INDEX idx_costs_on ON costs (incurred_on);
CREATE INDEX idx_costs_model ON costs (model_id);

CREATE TABLE links (
    id          INTEGER PRIMARY KEY,
    model_id    INTEGER NOT NULL REFERENCES models (id) ON DELETE CASCADE,
    code        TEXT    NOT NULL UNIQUE,
    label       TEXT    NOT NULL,
    source      TEXT    NOT NULL,
    target_url  TEXT    NOT NULL,
    is_active   INTEGER NOT NULL DEFAULT 1,
    is_premium  INTEGER NOT NULL DEFAULT 0,
    show_on_page INTEGER NOT NULL DEFAULT 0,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);

CREATE TABLE link_clicks_daily (
    link_id INTEGER NOT NULL REFERENCES links (id) ON DELETE CASCADE,
    day     TEXT    NOT NULL,
    clicks  INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (link_id, day)
);

CREATE TABLE subscriber_stats_daily (
    account_id      INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    day             TEXT    NOT NULL,
    new_subscribers INTEGER NOT NULL DEFAULT 0,
    cancelled       INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id, day)
);

-- Kurzy ČNB: jen pracovní dny, pro víkend/svátek platí poslední předchozí kurz.
CREATE TABLE exchange_rates (
    currency     TEXT NOT NULL,
    rate_date    TEXT NOT NULL,
    czk_per_unit REAL NOT NULL,
    PRIMARY KEY (currency, rate_date)
);

CREATE TABLE exchange_rate_months (
    currency   TEXT NOT NULL,
    year_month TEXT NOT NULL,
    fetched_at TEXT NOT NULL,
    PRIMARY KEY (currency, year_month)
);

CREATE TABLE sync_runs (
    id          INTEGER PRIMARY KEY,
    account_id  INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    started_at  TEXT    NOT NULL,
    finished_at TEXT,
    status      TEXT    NOT NULL,
    imported    INTEGER NOT NULL DEFAULT 0,
    message     TEXT
);
CREATE INDEX idx_sync_runs_account ON sync_runs (account_id, started_at);
