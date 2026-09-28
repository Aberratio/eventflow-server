CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('participant','organizer','moderator','admin','superadmin') NOT NULL DEFAULT 'participant',
  avatar VARCHAR(512) NULL,
  phone VARCHAR(64) NULL,
  birth_year INT NULL,
  gender ENUM('M','K') NULL,
  club VARCHAR(180) NULL,
  company_name VARCHAR(255) NULL,
  nip VARCHAR(32) NULL,
  website VARCHAR(512) NULL,
  description TEXT NULL,
  status ENUM('active','blocked') NOT NULL DEFAULT 'active',
  event_limit INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users MODIFY role ENUM('participant','organizer','moderator','admin','superadmin') NOT NULL DEFAULT 'participant';
-- @guard: column-missing users.event_limit
ALTER TABLE users ADD COLUMN event_limit INT NOT NULL DEFAULT 0 AFTER status;

CREATE TABLE IF NOT EXISTS series (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  type ENUM('virtual','local','championship','cup') NOT NULL,
  description TEXT NOT NULL,
  organizer_id VARCHAR(36) NOT NULL,
  organizer_name VARCHAR(180) NOT NULL,
  rules TEXT NULL,
  start_date VARCHAR(64) NULL,
  end_date VARCHAR(64) NULL,
  status ENUM('active','completed','upcoming') NOT NULL DEFAULT 'upcoming',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_series_organizer (organizer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE series
  MODIFY status ENUM('active','completed','upcoming','archived') NOT NULL DEFAULT 'upcoming';

CREATE TABLE IF NOT EXISTS events (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  discipline ENUM('running','cycling','triathlon','multisport') NOT NULL,
  date VARCHAR(64) NOT NULL,
  location VARCHAR(255) NOT NULL,
  city VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  image_url VARCHAR(512) NOT NULL,
  series_id VARCHAR(36) NULL,
  organizer_id VARCHAR(36) NOT NULL,
  organizer_name VARCHAR(180) NOT NULL,
  organizer_contact JSON NULL,
  status ENUM('draft','active','past','archived','deleted') NOT NULL DEFAULT 'draft',
  rejection_reason TEXT NULL,
  submitted_at VARCHAR(64) NULL,
  approved_at VARCHAR(64) NULL,
  approved_by VARCHAR(36) NULL,
  published_at VARCHAR(64) NULL,
  published_by VARCHAR(36) NULL,
  created_by VARCHAR(36) NULL,
  registration_deadline VARCHAR(64) NOT NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  live_results_url VARCHAR(512) NULL,
  is_live TINYINT(1) NOT NULL DEFAULT 0,
  registration_status ENUM('open','closed','none') NULL,
  route_map_url VARCHAR(512) NULL,
  route_gpx_url VARCHAR(512) NULL,
  route_elevation_profile VARCHAR(512) NULL,
  banner_url VARCHAR(512) NULL,
  banner_crop_area JSON NULL,
  organizer_logo_url VARCHAR(512) NULL,
  organizer_logo_shape ENUM('square','portrait','landscape') NOT NULL DEFAULT 'square',
  is_virtual TINYINT(1) NOT NULL DEFAULT 0,
  event_credit_kind ENUM('standard','virtual','timing') NOT NULL DEFAULT 'standard',
  start_date VARCHAR(64) NULL,
  end_date VARCHAR(64) NULL,
  payment_config JSON NULL,
  organizers_html MEDIUMTEXT NULL,
  rules_html MEDIUMTEXT NULL,
  final_acceptance_html MEDIUMTEXT NULL,
  require_final_acceptance TINYINT(1) NOT NULL DEFAULT 0,
  is_private TINYINT(1) NOT NULL DEFAULT 0,
  access_code VARCHAR(120) NULL,
  registration_embed JSON NULL,
  registration_form_config JSON NULL,
  display_options JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_events_filters (discipline, city, status, registration_status),
  INDEX idx_events_organizer (organizer_id),
  INDEX idx_events_series (series_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E1.1 (031_e11_event_lifecycle.sql) eventually drops `events.status` in favour of
-- `publication_status`/`visibility`/`is_test`/`deleted_at`. This file must stay
-- idempotent regardless of the ledger (bootstrap on an existing DB, crash recovery,
-- an edited file all replay it — see Migrator.php docblock), so once that column is
-- gone this MODIFY (and every other `status`-touching statement below, down to the
-- events section of this file) must become a no-op instead of failing.
SET @events_status_still_present = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'events' AND column_name = 'status'
);

SET @modify_events_status_wide_sql = IF(
  @events_status_still_present > 0,
  'ALTER TABLE events MODIFY status ENUM(''draft'',''awaiting_approval'',''approved'',''published'',''completed'',''cancelled'',''rejected'',''active'',''past'',''archived'',''deleted'') NOT NULL DEFAULT ''draft''',
  'DO 0'
);
PREPARE modify_events_status_wide_stmt FROM @modify_events_status_wide_sql;
EXECUTE modify_events_status_wide_stmt;
DEALLOCATE PREPARE modify_events_status_wide_stmt;

-- @guard: column-missing events.approved_at
ALTER TABLE events ADD COLUMN approved_at VARCHAR(64) NULL;
-- @guard: column-missing events.approved_by
ALTER TABLE events ADD COLUMN approved_by VARCHAR(36) NULL;
-- @guard: column-missing events.published_at
ALTER TABLE events ADD COLUMN published_at VARCHAR(64) NULL;
-- @guard: column-missing events.published_by
ALTER TABLE events ADD COLUMN published_by VARCHAR(36) NULL;
-- @guard: column-missing events.event_credit_kind
ALTER TABLE events ADD COLUMN event_credit_kind ENUM('standard','virtual','timing') NOT NULL DEFAULT 'standard' AFTER is_virtual;
-- @guard: column-missing events.organizer_logo_shape
ALTER TABLE events ADD COLUMN organizer_logo_shape ENUM('square','portrait','landscape') NOT NULL DEFAULT 'square' AFTER organizer_logo_url;
-- @guard: column-missing events.created_by
ALTER TABLE events ADD COLUMN created_by VARCHAR(36) NULL;

CREATE TABLE IF NOT EXISTS organizer_event_credit_batches (
  id VARCHAR(36) PRIMARY KEY,
  organizer_id VARCHAR(36) NOT NULL,
  event_credit_kind ENUM('standard','virtual','timing') NOT NULL DEFAULT 'standard',
  total_count INT NOT NULL,
  used_count INT NOT NULL DEFAULT 0,
  source_type VARCHAR(80) NOT NULL DEFAULT 'manual',
  source_id VARCHAR(120) NULL,
  created_at VARCHAR(64) NOT NULL,
  expires_at VARCHAR(64) NULL,
  INDEX idx_event_credit_batches_organizer (organizer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @guard: column-missing organizer_event_credit_batches.event_credit_kind
ALTER TABLE organizer_event_credit_batches ADD COLUMN event_credit_kind ENUM('standard','virtual','timing') NOT NULL DEFAULT 'standard' AFTER organizer_id;

CREATE TABLE IF NOT EXISTS organizer_event_credit_usages (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL UNIQUE,
  organizer_id VARCHAR(36) NOT NULL,
  batch_id VARCHAR(36) NOT NULL,
  event_credit_kind ENUM('standard','virtual','timing') NOT NULL DEFAULT 'standard',
  used_at VARCHAR(64) NOT NULL,
  INDEX idx_event_credit_usages_organizer (organizer_id),
  INDEX idx_event_credit_usages_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @guard: column-missing organizer_event_credit_usages.event_credit_kind
ALTER TABLE organizer_event_credit_usages ADD COLUMN event_credit_kind ENUM('standard','virtual','timing') NOT NULL DEFAULT 'standard' AFTER batch_id;

-- E1.1 (031_e11_event_lifecycle.sql) drops `events.status` entirely in favour of
-- `publication_status` / `visibility` / `is_test` / `deleted_at`. This block predates
-- that and is guarded the same way 028_e02_remove_tickets.sql guards its own
-- reference to a table a later migration removes — this file must stay idempotent
-- regardless of the ledger (see Migrator.php docblock), so once this column is gone
-- the unconditional form below would fail without this guard.
SET @events_status_still_present = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'events' AND column_name = 'status'
);

SET @normalize_events_status_sql = IF(
  @events_status_still_present > 0,
  'UPDATE events
   SET status = CASE status
     WHEN ''published'' THEN ''active''
     WHEN ''completed'' THEN ''past''
     WHEN ''cancelled'' THEN ''archived''
     WHEN ''active'' THEN ''active''
     WHEN ''past'' THEN ''past''
     WHEN ''archived'' THEN ''archived''
     WHEN ''deleted'' THEN ''deleted''
     ELSE ''draft''
   END',
  'DO 0'
);
PREPARE normalize_events_status_stmt FROM @normalize_events_status_sql;
EXECUTE normalize_events_status_stmt;
DEALLOCATE PREPARE normalize_events_status_stmt;

SET @modify_events_status_sql = IF(
  @events_status_still_present > 0,
  'ALTER TABLE events MODIFY status ENUM(''draft'',''awaiting_approval'',''approved'',''active'',''past'',''archived'',''deleted'') NOT NULL DEFAULT ''draft''',
  'DO 0'
);
PREPARE modify_events_status_stmt FROM @modify_events_status_sql;
EXECUTE modify_events_status_stmt;
DEALLOCATE PREPARE modify_events_status_stmt;

-- "Not soft-deleted" used to mean `status <> 'deleted'`; E1.1 moved that to its own
-- `deleted_at` column (see 031_e11_event_lifecycle.sql). Same replay problem as
-- above: pick whichever predicate matches the column that actually exists right now.
SET @events_not_deleted_predicate = IF(
  @events_status_still_present > 0,
  'e.status <> ''deleted''',
  'e.deleted_at IS NULL'
);
SET @sync_event_limit_sql = CONCAT(
  'UPDATE users u
   SET event_limit = GREATEST(
     COALESCE((SELECT SUM(b.total_count) FROM organizer_event_credit_batches b WHERE b.organizer_id = u.id), 0),
     COALESCE((SELECT COUNT(*) FROM events e WHERE e.organizer_id = u.id AND ', @events_not_deleted_predicate, '), 0)
   )
   WHERE u.role = ''organizer'''
);
PREPARE sync_event_limit_stmt FROM @sync_event_limit_sql;
EXECUTE sync_event_limit_stmt;
DEALLOCATE PREPARE sync_event_limit_stmt;

CREATE TABLE IF NOT EXISTS series_events (
  series_id VARCHAR(36) NOT NULL,
  event_id VARCHAR(36) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (series_id, event_id),
  INDEX idx_series_events_series (series_id),
  INDEX idx_series_events_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_categories (
  id VARCHAR(80) PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  sort_order INT NOT NULL DEFAULT 99,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_products (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  description TEXT NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  original_price DECIMAL(10,2) NULL,
  category VARCHAR(80) NOT NULL,
  brand VARCHAR(180) NOT NULL,
  sizes JSON NULL,
  colors JSON NULL,
  image_url VARCHAR(512) NOT NULL,
  images JSON NULL,
  in_stock TINYINT(1) NOT NULL DEFAULT 1,
  stock_count INT NULL,
  rating DECIMAL(3,2) NULL,
  review_count INT NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  is_service TINYINT(1) NOT NULL DEFAULT 0,
  service_details TEXT NULL,
  validity_days INT NULL,
  visible_to_roles JSON NULL,
  service_kind VARCHAR(80) NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_shop_products_category (category),
  INDEX idx_shop_products_status (status, in_stock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS series_products (
  series_id VARCHAR(36) NOT NULL,
  product_id VARCHAR(36) NOT NULL,
  purpose ENUM('reward','addon') NOT NULL DEFAULT 'reward',
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (series_id, product_id),
  INDEX idx_series_products_series (series_id),
  INDEX idx_series_products_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenges (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  description TEXT NOT NULL,
  image_url VARCHAR(512) NULL,
  series_id VARCHAR(36) NULL,
  min_completed INT NOT NULL DEFAULT 1,
  max_time VARCHAR(32) NULL,
  start_date VARCHAR(64) NULL,
  end_date VARCHAR(64) NULL,
  status ENUM('active','completed','upcoming','archived') NOT NULL DEFAULT 'upcoming',
  created_by VARCHAR(36) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_challenges_status (status),
  INDEX idx_challenges_series (series_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenge_events (
  challenge_id VARCHAR(36) NOT NULL,
  event_id VARCHAR(36) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (challenge_id, event_id),
  INDEX idx_challenge_events_challenge (challenge_id),
  INDEX idx_challenge_events_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenge_products (
  challenge_id VARCHAR(36) NOT NULL,
  product_id VARCHAR(36) NOT NULL,
  purpose ENUM('reward','addon') NOT NULL DEFAULT 'reward',
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (challenge_id, product_id),
  INDEX idx_challenge_products_challenge (challenge_id),
  INDEX idx_challenge_products_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenge_participations (
  id VARCHAR(36) PRIMARY KEY,
  challenge_id VARCHAR(36) NOT NULL,
  user_id VARCHAR(36) NOT NULL,
  user_name VARCHAR(180) NOT NULL,
  joined_at VARCHAR(64) NOT NULL,
  reward_granted TINYINT(1) NOT NULL DEFAULT 0,
  reward_granted_at VARCHAR(64) NULL,
  selected_rewards JSON NULL,
  assigned_number INT NULL,
  UNIQUE KEY uniq_challenge_participant (challenge_id, user_id),
  INDEX idx_challenge_participations_challenge (challenge_id),
  INDEX idx_challenge_participations_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenge_entries (
  id VARCHAR(36) PRIMARY KEY,
  challenge_id VARCHAR(36) NOT NULL,
  user_id VARCHAR(36) NOT NULL,
  event_id VARCHAR(36) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  submitted_time VARCHAR(32) NULL,
  submitted_position INT NULL,
  proof_url VARCHAR(512) NULL,
  submitted_at VARCHAR(64) NULL,
  reviewed_at VARCHAR(64) NULL,
  reviewed_by VARCHAR(36) NULL,
  rejection_reason TEXT NULL,
  source ENUM('manual','result') NOT NULL DEFAULT 'manual',
  UNIQUE KEY uniq_challenge_entry_event (challenge_id, user_id, event_id),
  INDEX idx_challenge_entries_challenge (challenge_id),
  INDEX idx_challenge_entries_user (user_id),
  INDEX idx_challenge_entries_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_distances (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  length DECIMAL(10,3) NOT NULL,
  unit ENUM('km','m') NOT NULL,
  categories JSON NULL,
  start_type ENUM('individual','relay') NOT NULL DEFAULT 'individual',
  relay_min_team_size INT NULL,
  relay_max_team_size INT NULL,
  relay_team_completion_deadline DATE NULL,
  age_categories JSON NULL,
  special_categories JSON NULL,
  participant_limit INT NULL,
  registered_count INT NOT NULL DEFAULT 0,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  office_price DECIMAL(10,2) NULL,
  info_html TEXT NULL,
  price_tier_mode VARCHAR(32) NULL,
  participant_count_type VARCHAR(32) NULL,
  price_tiers JSON NULL,
  vip_prices JSON NULL,
  route_map_url VARCHAR(512) NULL,
  route_elevation_profile VARCHAR(512) NULL,
  route_gpx_url VARCHAR(512) NULL,
  route_files JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_event_distances_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_payment_methods (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_event_payment_methods_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_merchandise (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  sizes JSON NULL,
  colors JSON NULL,
  image_url VARCHAR(512) NULL,
  available TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_event_merchandise_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_discount_codes (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  code VARCHAR(80) NOT NULL,
  discount_type ENUM('percent','fixed') NOT NULL,
  discount_value DECIMAL(10,2) NOT NULL,
  valid_from VARCHAR(64) NULL,
  valid_to VARCHAR(64) NULL,
  max_uses INT NULL,
  used_count INT NOT NULL DEFAULT 0,
  distance_ids JSON NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  used_by JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_event_discount_codes_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_gallery_albums (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  url VARCHAR(512) NOT NULL,
  platform VARCHAR(64) NOT NULL,
  photographer VARCHAR(180) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_event_gallery_albums_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_result_documents (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  type ENUM('open','category','team','distance') NOT NULL DEFAULT 'open',
  distance_id VARCHAR(36) NULL,
  distance_name VARCHAR(180) NULL,
  file_url VARCHAR(512) NOT NULL,
  uploaded_at VARCHAR(64) NOT NULL,
  uploaded_by VARCHAR(180) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_event_result_documents_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  event_name VARCHAR(255) NOT NULL,
  user_id VARCHAR(36) NOT NULL,
  user_name VARCHAR(180) NOT NULL,
  user_email VARCHAR(255) NOT NULL,
  distance_id VARCHAR(36) NOT NULL,
  distance_name VARCHAR(180) NOT NULL,
  category VARCHAR(80) NOT NULL,
  registered_at VARCHAR(64) NOT NULL,
  status ENUM('pending','confirmed','cancelled','removed') NOT NULL DEFAULT 'pending',
  bib_number VARCHAR(32) NULL,
  team_name VARCHAR(180) NULL,
  team_id VARCHAR(36) NULL,
  relay_members JSON NULL,
  price_paid DECIMAL(10,2) NULL,
  discount_code VARCHAR(80) NULL,
  discount_amount DECIMAL(10,2) NULL,
  insurance_paid DECIMAL(10,2) NULL,
  removed_at VARCHAR(64) NULL,
  INDEX idx_reg_event (event_id),
  INDEX idx_reg_user (user_id),
  INDEX idx_reg_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registration_merchandise_orders (
  id VARCHAR(36) PRIMARY KEY,
  registration_id VARCHAR(36) NOT NULL,
  merchandise_id VARCHAR(36) NOT NULL,
  merchandise_name VARCHAR(180) NOT NULL,
  size VARCHAR(40) NULL,
  color VARCHAR(80) NULL,
  color_hex VARCHAR(20) NULL,
  quantity INT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  INDEX idx_registration_merchandise_registration (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registration_payment_details (
  registration_id VARCHAR(36) PRIMARY KEY,
  start_fee_paid TINYINT(1) NOT NULL DEFAULT 0,
  start_fee_paid_at VARCHAR(64) NULL,
  start_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  insurance_paid TINYINT(1) NOT NULL DEFAULT 0,
  insurance_paid_at VARCHAR(64) NULL,
  insurance_amount DECIMAL(10,2) NULL,
  merchandise_payments JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS results (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  event_name VARCHAR(255) NOT NULL,
  distance_id VARCHAR(36) NOT NULL,
  distance_name VARCHAR(180) NOT NULL,
  user_id VARCHAR(36) NOT NULL,
  user_name VARCHAR(180) NOT NULL,
  bib_number VARCHAR(32) NOT NULL,
  category VARCHAR(80) NOT NULL,
  time VARCHAR(32) NOT NULL,
  time_in_seconds INT NOT NULL,
  position INT NOT NULL,
  category_position INT NOT NULL,
  status ENUM('finished','dnf','dsq') NOT NULL DEFAULT 'finished',
  gender ENUM('M','K') NULL,
  birth_year INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_results_event (event_id, distance_id),
  INDEX idx_results_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_results (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  distance_id VARCHAR(36) NOT NULL,
  distance_name VARCHAR(180) NOT NULL,
  team_name VARCHAR(180) NOT NULL,
  members JSON NOT NULL,
  total_time VARCHAR(32) NOT NULL,
  total_time_in_seconds INT NOT NULL,
  position INT NOT NULL,
  INDEX idx_team_results_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS info_pages (
  id VARCHAR(36) PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  icon VARCHAR(80) NOT NULL,
  description TEXT NOT NULL,
  content JSON NOT NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  show_in_footer TINYINT(1) NOT NULL DEFAULT 0,
  show_in_header TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 99,
  created_at VARCHAR(64) NOT NULL,
  updated_at VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partners (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  logo_url VARCHAR(512) NOT NULL,
  logo_dark_url VARCHAR(512) NULL,
  website_url VARCHAR(512) NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 99,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_partners_featured (featured, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  action VARCHAR(80) NOT NULL,
  description TEXT NOT NULL,
  target_type VARCHAR(80) NULL,
  target_id VARCHAR(36) NULL,
  target_name VARCHAR(180) NULL,
  ip_address VARCHAR(80) NULL,
  user_agent VARCHAR(255) NULL,
  timestamp VARCHAR(64) NOT NULL,
  INDEX idx_activity_filters (action, user_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_settings (
  id VARCHAR(36) PRIMARY KEY,
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  two_factor_method VARCHAR(20) NULL,
  last_password_change VARCHAR(64) NOT NULL,
  password_expiry_days INT NOT NULL DEFAULT 90,
  session_timeout_minutes INT NOT NULL DEFAULT 60,
  trusted_devices JSON NOT NULL,
  login_history JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS online_sessions (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  user_name VARCHAR(180) NOT NULL,
  role ENUM('admin','superadmin','moderator') NOT NULL,
  login_at VARCHAR(64) NOT NULL,
  last_active_at VARCHAR(64) NOT NULL,
  ip_address VARCHAR(80) NULL,
  browser VARCHAR(120) NULL,
  location VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE online_sessions
  MODIFY role ENUM('admin','superadmin','moderator') NOT NULL;

CREATE TABLE IF NOT EXISTS password_resets (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at VARCHAR(64) NOT NULL,
  used_at VARCHAR(64) NULL,
  INDEX idx_password_resets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend event_merchandise with shop-product-compatible fields
-- @guard: column-missing event_merchandise.images
ALTER TABLE event_merchandise ADD COLUMN images JSON NULL AFTER image_url;
-- @guard: column-missing event_merchandise.original_price
ALTER TABLE event_merchandise ADD COLUMN original_price DECIMAL(10,2) NULL AFTER price;
-- @guard: column-missing event_merchandise.brand
ALTER TABLE event_merchandise ADD COLUMN brand VARCHAR(180) NULL AFTER description;
-- @guard: column-missing event_merchandise.description_html
ALTER TABLE event_merchandise ADD COLUMN description_html MEDIUMTEXT NULL AFTER brand;
-- @guard: column-missing event_merchandise.documents
ALTER TABLE event_merchandise ADD COLUMN documents JSON NULL AFTER description_html;
-- @guard: column-missing event_merchandise.quantity_mode
ALTER TABLE event_merchandise ADD COLUMN quantity_mode ENUM('any','max','one') NOT NULL DEFAULT 'any' AFTER sort_order;
-- @guard: column-missing event_merchandise.quantity_max
ALTER TABLE event_merchandise ADD COLUMN quantity_max INT NULL AFTER quantity_mode;
-- @guard: column-missing event_merchandise.sale_from
ALTER TABLE event_merchandise ADD COLUMN sale_from VARCHAR(64) NULL AFTER quantity_max;
-- @guard: column-missing event_merchandise.sale_to
ALTER TABLE event_merchandise ADD COLUMN sale_to VARCHAR(64) NULL AFTER sale_from;
-- @guard: column-missing event_merchandise.stock_count
ALTER TABLE event_merchandise ADD COLUMN stock_count INT NULL AFTER available;
-- @guard: column-missing event_merchandise.distance_ids
ALTER TABLE event_merchandise ADD COLUMN distance_ids JSON NULL AFTER quantity_max;
-- @guard: column-missing event_merchandise.shop_product_id
ALTER TABLE event_merchandise ADD COLUMN shop_product_id VARCHAR(36) NULL AFTER distance_ids;
