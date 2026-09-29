-- SMM Panel database schema
-- MySQL 5.7+/8.x or MariaDB 10.3+, InnoDB, utf8mb4.
-- All DATETIME values are stored in UTC.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  `key`        VARCHAR(100) NOT NULL,
  `value`      MEDIUMTEXT NULL,
  is_secret    TINYINT(1) NOT NULL DEFAULT 0,
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Users & wallet
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS price_levels (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(60) NOT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  min_spent        DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  created_at       DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username           VARCHAR(40) NOT NULL,
  email              VARCHAR(190) NOT NULL,
  password_hash      VARCHAR(255) NOT NULL,
  name               VARCHAR(100) NULL,
  status             ENUM('active','suspended','banned') NOT NULL DEFAULT 'active',
  email_verified_at  DATETIME NULL,
  price_level_id     INT UNSIGNED NULL,
  custom_discount    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  referral_code      VARCHAR(20) NOT NULL,
  referred_by        INT UNSIGNED NULL,
  timezone           VARCHAR(64) NULL,
  twofa_secret       VARCHAR(255) NULL,
  twofa_enabled      TINYINT(1) NOT NULL DEFAULT 0,
  api_enabled        TINYINT(1) NOT NULL DEFAULT 1,
  register_ip        VARCHAR(45) NULL,
  last_login_at      DATETIME NULL,
  last_login_ip      VARCHAR(45) NULL,
  session_version    INT UNSIGNED NOT NULL DEFAULT 1,
  admin_note         TEXT NULL,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_refcode (referral_code),
  KEY idx_users_status (status),
  KEY idx_users_created (created_at),
  KEY idx_users_referred (referred_by),
  CONSTRAINT fk_users_level FOREIGN KEY (price_level_id) REFERENCES price_levels(id) ON DELETE SET NULL,
  CONSTRAINT fk_users_referrer FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One wallet per user. Balances are ONLY modified by App\Services\WalletService.
CREATE TABLE IF NOT EXISTS wallets (
  user_id           INT UNSIGNED NOT NULL,
  balance           DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  referral_balance  DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  total_deposits    DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  total_spent       DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  allow_negative    TINYINT(1) NOT NULL DEFAULT 0,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable ledger. `reference` is unique so the same financial event can never be applied twice.
CREATE TABLE IF NOT EXISTS transactions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  type            ENUM('deposit','order_charge','refund','manual_adjustment','bonus','affiliate_commission','affiliate_withdrawal') NOT NULL,
  wallet          ENUM('main','referral') NOT NULL DEFAULT 'main',
  amount          DECIMAL(18,6) NOT NULL,
  balance_before  DECIMAL(18,6) NOT NULL,
  balance_after   DECIMAL(18,6) NOT NULL,
  reference       VARCHAR(100) NULL,
  description     VARCHAR(255) NULL,
  order_id        BIGINT UNSIGNED NULL,
  payment_id      BIGINT UNSIGNED NULL,
  admin_id        INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tx_reference (reference),
  KEY idx_tx_user_created (user_id, created_at),
  KEY idx_tx_type (type),
  KEY idx_tx_order (order_id),
  KEY idx_tx_payment (payment_id),
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  guard       ENUM('user','admin') NOT NULL DEFAULT 'user',
  account_id  INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_token (token_hash),
  KEY idx_pr_account (guard, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ev_token (token_hash),
  KEY idx_ev_user (user_id),
  CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  guard       ENUM('user','admin') NOT NULL,
  identifier  VARCHAR(190) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  success     TINYINT(1) NOT NULL DEFAULT 0,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_la_ident (guard, identifier, created_at),
  KEY idx_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Admins, roles, permissions
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username        VARCHAR(40) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  name            VARCHAR(100) NULL,
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  is_super        TINYINT(1) NOT NULL DEFAULT 0,
  twofa_secret    VARCHAR(255) NULL,
  twofa_enabled   TINYINT(1) NOT NULL DEFAULT 0,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at   DATETIME NULL,
  last_login_ip   VARCHAR(45) NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username),
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60) NOT NULL,
  description VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80) NOT NULL,
  label       VARCHAR(150) NOT NULL,
  grp         VARCHAR(60) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_perm_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_roles (
  admin_id INT UNSIGNED NOT NULL,
  role_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (admin_id, role_id),
  CONSTRAINT fk_ar_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
  CONSTRAINT fk_ar_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Catalog & providers
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS providers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(100) NOT NULL,
  adapter           VARCHAR(50) NOT NULL DEFAULT 'standard_v2',
  api_url           VARCHAR(255) NOT NULL,
  api_key_enc       TEXT NULL,
  currency          CHAR(3) NOT NULL DEFAULT 'USD',
  exchange_rate     DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
  config            TEXT NULL,
  status            ENUM('active','disabled') NOT NULL DEFAULT 'active',
  timeout           SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  balance           DECIMAL(18,6) NULL,
  connection_status ENUM('unknown','ok','error') NOT NULL DEFAULT 'unknown',
  last_error        VARCHAR(500) NULL,
  last_checked_at   DATETIME NULL,
  last_synced_at    DATETIME NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cached copy of each provider's catalog (from the "services" action).
CREATE TABLE IF NOT EXISTS provider_services (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id         INT UNSIGNED NOT NULL,
  provider_service_id VARCHAR(50) NOT NULL,
  name                VARCHAR(255) NOT NULL,
  category            VARCHAR(255) NULL,
  type                VARCHAR(60) NULL,
  rate                DECIMAL(18,6) NOT NULL,
  min_quantity        INT UNSIGNED NOT NULL DEFAULT 1,
  max_quantity        INT UNSIGNED NOT NULL DEFAULT 1,
  refill              TINYINT(1) NOT NULL DEFAULT 0,
  cancel              TINYINT(1) NOT NULL DEFAULT 0,
  dripfeed            TINYINT(1) NOT NULL DEFAULT 0,
  description         TEXT NULL,
  is_available        TINYINT(1) NOT NULL DEFAULT 1,
  synced_at           DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ps (provider_id, provider_service_id),
  CONSTRAINT fk_ps_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(150) NOT NULL,
  slug        VARCHAR(170) NOT NULL,
  icon        VARCHAR(40) NULL,
  description VARCHAR(500) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  status      ENUM('active','hidden') NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_slug (slug),
  KEY idx_cat_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id         INT UNSIGNED NOT NULL,
  name                VARCHAR(255) NOT NULL,
  description         TEXT NULL,
  type                ENUM('default','package','custom_comments','custom_comments_package','mentions_custom_list','comment_likes','poll','keywords') NOT NULL DEFAULT 'default',
  link_label          VARCHAR(60) NOT NULL DEFAULT 'Link',
  provider_id         INT UNSIGNED NULL,
  provider_service_id VARCHAR(50) NULL,
  provider_rate       DECIMAL(18,6) NULL,
  rate                DECIMAL(18,6) NOT NULL,
  markup_percent      DECIMAL(7,2) NULL,
  auto_sync           TINYINT(1) NOT NULL DEFAULT 0,
  min_quantity        INT UNSIGNED NOT NULL DEFAULT 10,
  max_quantity        INT UNSIGNED NOT NULL DEFAULT 100000,
  average_time        VARCHAR(60) NULL,
  dripfeed            TINYINT(1) NOT NULL DEFAULT 0,
  refill              TINYINT(1) NOT NULL DEFAULT 0,
  refill_days         SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  cancel              TINYINT(1) NOT NULL DEFAULT 0,
  custom_fields       TEXT NULL,
  status              ENUM('active','disabled') NOT NULL DEFAULT 'active',
  is_hidden           TINYINT(1) NOT NULL DEFAULT 0,
  sort_order          INT NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_svc_cat (category_id, status, sort_order),
  KEY idx_svc_provider (provider_id, provider_service_id),
  CONSTRAINT fk_svc_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_svc_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Orders
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED NOT NULL,
  service_id         INT UNSIGNED NOT NULL,
  provider_id        INT UNSIGNED NULL,
  provider_order_id  VARCHAR(64) NULL,
  link               VARCHAR(1000) NOT NULL,
  quantity           INT UNSIGNED NOT NULL,
  extra              TEXT NULL,
  runs               INT UNSIGNED NULL,
  `interval`         INT UNSIGNED NULL,
  rate               DECIMAL(18,6) NOT NULL,
  charge             DECIMAL(18,6) NOT NULL,
  cost               DECIMAL(18,6) NULL,
  refunded_amount    DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  start_count        INT UNSIGNED NULL,
  remains            INT UNSIGNED NULL,
  status             ENUM('pending','processing','in_progress','completed','partial','cancelled','refunded','failed') NOT NULL DEFAULT 'pending',
  submit_state       ENUM('queued','submitting','submitted','unknown','manual','failed') NOT NULL DEFAULT 'queued',
  submit_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error         VARCHAR(500) NULL,
  source             ENUM('web','mass','api','admin') NOT NULL DEFAULT 'web',
  idempotency_key    VARCHAR(64) NULL,
  cancel_requested   TINYINT(1) NOT NULL DEFAULT 0,
  needs_attention    TINYINT(1) NOT NULL DEFAULT 0,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  last_synced_at     DATETIME NULL,
  completed_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_idem (user_id, idempotency_key),
  KEY idx_order_user_created (user_id, created_at),
  KEY idx_order_user_status (user_id, status),
  KEY idx_order_status_sync (status, last_synced_at),
  KEY idx_order_submit (submit_state, created_at),
  KEY idx_order_provider (provider_id, provider_order_id),
  KEY idx_order_service (service_id),
  KEY idx_order_created (created_at),
  CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    BIGINT UNSIGNED NOT NULL,
  event       VARCHAR(60) NOT NULL,
  old_status  VARCHAR(20) NULL,
  new_status  VARCHAR(20) NULL,
  message     VARCHAR(1000) NULL,
  actor       VARCHAR(40) NOT NULL DEFAULT 'system',
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ol_order (order_id, created_at),
  CONSTRAINT fk_ol_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refills (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id            BIGINT UNSIGNED NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  provider_refill_id  VARCHAR(64) NULL,
  status              ENUM('pending','processing','completed','rejected','failed') NOT NULL DEFAULT 'pending',
  message             VARCHAR(500) NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_refill_order (order_id),
  KEY idx_refill_user (user_id, created_at),
  KEY idx_refill_status (status),
  CONSTRAINT fk_refill_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_refill_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Payments
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_methods (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gateway       VARCHAR(40) NOT NULL,
  name          VARCHAR(100) NOT NULL,
  instructions  TEXT NULL,
  account       VARCHAR(500) NULL,
  qr_image      VARCHAR(255) NULL,
  min_amount    DECIMAL(18,4) NOT NULL DEFAULT 1.0000,
  max_amount    DECIMAL(18,4) NOT NULL DEFAULT 10000.0000,
  fee_percent   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  require_proof TINYINT(1) NOT NULL DEFAULT 0,
  credentials_enc TEXT NULL,
  config        TEXT NULL,
  status        ENUM('active','disabled') NOT NULL DEFAULT 'disabled',
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pm_status (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(40) NOT NULL,
  type             ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value            DECIMAL(18,4) NOT NULL,
  min_deposit      DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  max_discount     DECIMAL(18,4) NULL,
  usage_limit      INT UNSIGNED NULL,
  per_user_limit   INT UNSIGNED NOT NULL DEFAULT 1,
  used_count       INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at        DATETIME NULL,
  expires_at       DATETIME NULL,
  status           ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coupon_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  payment_method_id INT UNSIGNED NULL,
  gateway           VARCHAR(40) NOT NULL,
  amount            DECIMAL(18,4) NOT NULL,
  fee               DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  currency          CHAR(3) NOT NULL,
  gateway_ref       VARCHAR(128) NULL,
  status            ENUM('pending','completed','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  gateway_status    VARCHAR(40) NULL,
  pay_url           VARCHAR(1000) NULL,
  coupon_id         INT UNSIGNED NULL,
  bonus_amount      DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  meta              TEXT NULL,
  ip                VARCHAR(45) NULL,
  expires_at        DATETIME NULL,
  completed_at      DATETIME NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pay_gateway_ref (gateway, gateway_ref),
  KEY idx_pay_user (user_id, created_at),
  KEY idx_pay_status (status, expires_at),
  KEY idx_pay_created (created_at),
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_payment_requests (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  payment_method_id INT UNSIGNED NOT NULL,
  payment_id        BIGINT UNSIGNED NULL,
  coupon_id         INT UNSIGNED NULL,
  amount            DECIMAL(18,4) NOT NULL,
  reference         VARCHAR(120) NOT NULL,
  proof_path        VARCHAR(255) NULL,
  user_note         VARCHAR(500) NULL,
  status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_id          INT UNSIGNED NULL,
  admin_note        VARCHAR(500) NULL,
  reviewed_at       DATETIME NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mpr_reference (payment_method_id, reference),
  KEY idx_mpr_status (status, created_at),
  KEY idx_mpr_user (user_id, created_at),
  CONSTRAINT fk_mpr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpr_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_usage (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  coupon_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  payment_id  BIGINT UNSIGNED NOT NULL,
  amount      DECIMAL(18,4) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cu_payment (payment_id),
  KEY idx_cu_coupon_user (coupon_id, user_id),
  CONSTRAINT fk_cu_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
  CONSTRAINT fk_cu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Referrals
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS referrals (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id  INT UNSIGNED NOT NULL,
  referred_id  INT UNSIGNED NOT NULL,
  ip           VARCHAR(45) NULL,
  status       ENUM('active','blocked') NOT NULL DEFAULT 'active',
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ref_referred (referred_id),
  KEY idx_ref_referrer (referrer_id),
  CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_transactions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id  INT UNSIGNED NOT NULL,
  referred_id  INT UNSIGNED NOT NULL,
  payment_id   BIGINT UNSIGNED NOT NULL,
  base_amount  DECIMAL(18,4) NOT NULL,
  percent      DECIMAL(5,2) NOT NULL,
  commission   DECIMAL(18,6) NOT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rt_payment (payment_id),
  KEY idx_rt_referrer (referrer_id, created_at),
  CONSTRAINT fk_rt_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rt_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Support
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  subject       VARCHAR(200) NOT NULL,
  category      ENUM('order','payment','api','refill','other') NOT NULL DEFAULT 'other',
  order_ref     VARCHAR(200) NULL,
  status        ENUM('open','pending','answered','closed') NOT NULL DEFAULT 'open',
  priority      ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assigned_to   INT UNSIGNED NULL,
  user_unread   TINYINT(1) NOT NULL DEFAULT 0,
  admin_unread  TINYINT(1) NOT NULL DEFAULT 1,
  last_reply_at DATETIME NOT NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ticket_user (user_id, last_reply_at),
  KEY idx_ticket_status (status, last_reply_at),
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_admin FOREIGN KEY (assigned_to) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   BIGINT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  admin_id    INT UNSIGNED NULL,
  message     TEXT NOT NULL,
  is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_tm_ticket (ticket_id, id),
  CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_attachments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id  BIGINT UNSIGNED NOT NULL,
  path        VARCHAR(255) NOT NULL,
  original    VARCHAR(255) NOT NULL,
  mime        VARCHAR(100) NOT NULL,
  size        INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ta_msg (message_id),
  CONSTRAINT fk_ta_msg FOREIGN KEY (message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Notifications & content
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  type        VARCHAR(40) NOT NULL,
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(1000) NULL,
  url         VARCHAR(255) NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, read_at, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title       VARCHAR(200) NOT NULL,
  body        TEXT NOT NULL,
  level       ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  status      ENUM('active','hidden') NOT NULL DEFAULT 'active',
  starts_at   DATETIME NULL,
  ends_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ann_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug             VARCHAR(120) NOT NULL,
  title            VARCHAR(200) NOT NULL,
  content          MEDIUMTEXT NULL,
  seo_title        VARCHAR(200) NULL,
  seo_description  VARCHAR(320) NULL,
  status           ENUM('published','draft') NOT NULL DEFAULT 'published',
  is_system        TINYINT(1) NOT NULL DEFAULT 0,
  show_in_footer   TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_page_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question    VARCHAR(300) NOT NULL,
  answer      TEXT NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  status      ENUM('active','hidden') NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_faq_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  slug        VARCHAR(140) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bc_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_posts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id      INT UNSIGNED NULL,
  admin_id         INT UNSIGNED NULL,
  title            VARCHAR(220) NOT NULL,
  slug             VARCHAR(240) NOT NULL,
  excerpt          VARCHAR(500) NULL,
  content          MEDIUMTEXT NOT NULL,
  featured_image   VARCHAR(255) NULL,
  seo_title        VARCHAR(200) NULL,
  seo_description  VARCHAR(320) NULL,
  status           ENUM('published','draft') NOT NULL DEFAULT 'draft',
  published_at     DATETIME NULL,
  views            INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bp_slug (slug),
  KEY idx_bp_status (status, published_at),
  CONSTRAINT fk_bp_cat FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_bp_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_tags (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name  VARCHAR(80) NOT NULL,
  slug  VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bt_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_post_tags (
  post_id INT UNSIGNED NOT NULL,
  tag_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, tag_id),
  CONSTRAINT fk_bpt_post FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_bpt_tag FOREIGN KEY (tag_id) REFERENCES blog_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- API, logs, infrastructure
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  key_hash     CHAR(64) NOT NULL,
  key_prefix   VARCHAR(12) NOT NULL,
  status       ENUM('active','revoked') NOT NULL DEFAULT 'active',
  last_used_at DATETIME NULL,
  last_used_ip VARCHAR(45) NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_key_hash (key_hash),
  KEY idx_api_user (user_id, status),
  CONSTRAINT fk_api_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(30) NULL,
  ip           VARCHAR(45) NOT NULL,
  http_status  SMALLINT UNSIGNED NOT NULL,
  request      TEXT NULL,
  response     TEXT NULL,
  duration_ms  INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_apil_user (user_id, created_at),
  KEY idx_apil_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id  INT UNSIGNED NULL,
  action       VARCHAR(30) NOT NULL,
  http_status  SMALLINT UNSIGNED NULL,
  success      TINYINT(1) NOT NULL DEFAULT 0,
  request      TEXT NULL,
  response     MEDIUMTEXT NULL,
  error        VARCHAR(500) NULL,
  duration_ms  INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pl_provider (provider_id, created_at),
  KEY idx_pl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_logs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gateway          VARCHAR(40) NOT NULL,
  ip               VARCHAR(45) NOT NULL,
  headers          TEXT NULL,
  payload          MEDIUMTEXT NULL,
  signature_valid  TINYINT(1) NOT NULL DEFAULT 0,
  payment_id       BIGINT UNSIGNED NULL,
  result           VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_wl_gateway (gateway, created_at),
  KEY idx_wl_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type   ENUM('admin','user','system') NOT NULL,
  actor_id     INT UNSIGNED NULL,
  action       VARCHAR(80) NOT NULL,
  target_type  VARCHAR(40) NULL,
  target_id    VARCHAR(40) NULL,
  details      TEXT NULL,
  ip           VARCHAR(45) NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_actor (actor_type, actor_id, created_at),
  KEY idx_audit_target (target_type, target_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       VARCHAR(190) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email    VARCHAR(190) NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body_html   MEDIUMTEXT NOT NULL,
  status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL,
  sent_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_eq_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task         VARCHAR(60) NOT NULL,
  status       ENUM('running','success','failed','skipped') NOT NULL,
  output       TEXT NULL,
  duration_ms  INT UNSIGNED NOT NULL DEFAULT 0,
  started_at   DATETIME NOT NULL,
  finished_at  DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cron_task (task, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
