CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  email TEXT,
  role TEXT NOT NULL DEFAULT 'family_admin',
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS integrations (
  id TEXT PRIMARY KEY,
  user_id TEXT,
  provider TEXT NOT NULL,
  environment TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'not_connected',
  encrypted_credentials TEXT,
  access_token_expires_at TEXT,
  refresh_token_expires_at TEXT,
  last_health_check_at TEXT,
  last_error TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS cj_products (
  id TEXT PRIMARY KEY,
  title TEXT NOT NULL,
  category_id TEXT,
  supplier_id TEXT,
  raw_json TEXT NOT NULL,
  first_seen_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS cj_variants (
  id TEXT PRIMARY KEY,
  cj_product_id TEXT NOT NULL,
  sku TEXT,
  price REAL NOT NULL DEFAULT 0,
  weight REAL,
  inventory INTEGER NOT NULL DEFAULT 0,
  raw_json TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ebay_market_comparisons (
  id TEXT PRIMARY KEY,
  cj_product_id TEXT NOT NULL,
  query TEXT NOT NULL,
  average_price REAL NOT NULL,
  median_price REAL NOT NULL,
  recommended_price REAL NOT NULL,
  confidence_score REAL NOT NULL,
  raw_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS listing_drafts (
  id TEXT PRIMARY KEY,
  cj_product_id TEXT NOT NULL,
  cj_variant_id TEXT,
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  item_specifics_json TEXT NOT NULL,
  price REAL NOT NULL,
  estimated_profit REAL NOT NULL,
  duplicate_status TEXT NOT NULL,
  approval_status TEXT NOT NULL DEFAULT 'pending',
  raw_json TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ebay_listings (
  id TEXT PRIMARY KEY,
  listing_draft_id TEXT,
  ebay_item_id TEXT UNIQUE,
  title TEXT NOT NULL,
  price REAL NOT NULL,
  status TEXT NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 0,
  raw_json TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS product_connections (
  id TEXT PRIMARY KEY,
  cj_product_id TEXT NOT NULL,
  cj_variant_id TEXT,
  ebay_item_id TEXT,
  shop_id TEXT,
  raw_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS price_history (
  id TEXT PRIMARY KEY,
  listing_id TEXT NOT NULL,
  old_price REAL,
  new_price REAL NOT NULL,
  reason TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS optimization_events (
  id TEXT PRIMARY KEY,
  listing_id TEXT NOT NULL,
  event_type TEXT NOT NULL,
  recommendation TEXT NOT NULL,
  mode TEXT NOT NULL DEFAULT 'approval',
  status TEXT NOT NULL DEFAULT 'pending',
  reason TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS automation_rules (
  id TEXT PRIMARY KEY,
  user_id TEXT,
  fee_percentage REAL NOT NULL DEFAULT 15,
  ad_percentage REAL NOT NULL DEFAULT 2,
  minimum_profit REAL NOT NULL DEFAULT 0,
  maximum_profit REAL NOT NULL DEFAULT 450,
  price_drop_schedule_json TEXT NOT NULL,
  title_rewrite_after_days INTEGER NOT NULL DEFAULT 30,
  image_change_after_days INTEGER NOT NULL DEFAULT 30,
  end_listing_after_days INTEGER NOT NULL DEFAULT 60,
  auto_list_categories_json TEXT NOT NULL,
  automation_mode TEXT NOT NULL DEFAULT 'approval',
  dry_run INTEGER NOT NULL DEFAULT 1,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS image_assets (
  id TEXT PRIMARY KEY,
  cj_product_id TEXT NOT NULL,
  url TEXT NOT NULL,
  score REAL NOT NULL DEFAULT 0,
  score_reasons_json TEXT NOT NULL,
  is_main INTEGER NOT NULL DEFAULT 0,
  ai_enhancement_status TEXT NOT NULL DEFAULT 'not_configured',
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS duplicate_checks (
  id TEXT PRIMARY KEY,
  cj_product_id TEXT NOT NULL,
  risk_score REAL NOT NULL,
  status TEXT NOT NULL,
  reasons_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS job_logs (
  id TEXT PRIMARY KEY,
  job_name TEXT NOT NULL,
  status TEXT NOT NULL,
  message TEXT NOT NULL,
  metadata_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id TEXT PRIMARY KEY,
  actor TEXT NOT NULL,
  action TEXT NOT NULL,
  target_type TEXT NOT NULL,
  target_id TEXT,
  before_json TEXT,
  after_json TEXT,
  reason TEXT NOT NULL,
  created_at TEXT NOT NULL
);
