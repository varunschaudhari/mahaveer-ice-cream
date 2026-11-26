-- Mahavir Ice Cream – V1 schema
-- MySQL 8 / MariaDB 10.6+, InnoDB, utf8mb4
-- Applied by database/migrate.php (which tracks applied files in schema_migrations).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------
-- Settings, staff, audit
-- ---------------------------------------------------------------

CREATE TABLE settings (
  setting_key   VARCHAR(50)  NOT NULL PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100) NOT NULL,
  role            ENUM('staff','owner','super') NOT NULL DEFAULT 'staff',
  username        VARCHAR(50)  NULL UNIQUE,          -- owner/super login for admin
  password_hash   VARCHAR(255) NULL,
  pin_hash        VARCHAR(255) NULL,                 -- 4-digit PIN for counter / approvals
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  staff_id   INT NULL,
  action     VARCHAR(50)  NOT NULL,                  -- cancel_order, price_change, stock_toggle ...
  entity     VARCHAR(50)  NOT NULL,                  -- order, item_variant, setting ...
  entity_id  VARCHAR(50)  NULL,
  details    TEXT NULL,                              -- JSON: before/after, reason
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity (entity, entity_id),
  KEY idx_created (created_at),
  FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  rl_key     VARCHAR(120) NOT NULL,                  -- e.g. place_order:ip:1.2.3.4
  hit_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_key_time (rl_key, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Menu
-- ---------------------------------------------------------------

CREATE TABLE categories (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name_mr    VARCHAR(100) NOT NULL,
  name_en    VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE items (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  category_id    INT NOT NULL,
  name_mr        VARCHAR(100) NOT NULL,
  name_en        VARCHAR(100) NOT NULL,
  photo          VARCHAR(255) NULL,                  -- file name in storage/uploads
  is_available   TINYINT(1) NOT NULL DEFAULT 1,      -- daily sold-out toggle
  online_enabled TINYINT(1) NOT NULL DEFAULT 1,      -- show on takeaway/table/website
  sort_order     INT NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_category (category_id, sort_order),
  FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE item_variants (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  item_id    INT NOT NULL,
  label_mr   VARCHAR(50) NOT NULL,                   -- हाफ / फुल / ५०० ग्रॅम
  label_en   VARCHAR(50) NOT NULL,                   -- Half / Full / 500 g
  price      DECIMAL(8,2) NOT NULL,                  -- 0 = placeholder, never sellable
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_item (item_id, sort_order),
  FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Customers, numbering, tables
-- ---------------------------------------------------------------

CREATE TABLE customers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  mobile        CHAR(10) NOT NULL UNIQUE,
  name          VARCHAR(100) NOT NULL,
  order_count   INT NOT NULL DEFAULT 0,
  last_order_at DATETIME NULL,
  no_show_count INT NOT NULL DEFAULT 0,
  is_blocked    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_tokens (
  token_date DATE NOT NULL PRIMARY KEY,
  last_token INT  NOT NULL DEFAULT 0                 -- lock with SELECT ... FOR UPDATE
) ENGINE=InnoDB;

CREATE TABLE sequences (
  name       VARCHAR(30) NOT NULL PRIMARY KEY,       -- 'bill_no'
  last_value INT NOT NULL DEFAULT 0                  -- lock with SELECT ... FOR UPDATE
) ENGINE=InnoDB;

CREATE TABLE dining_tables (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(10) NOT NULL UNIQUE,            -- T1 ... T7
  area       ENUM('inside','outside') NOT NULL,
  qr_token   CHAR(32) NOT NULL UNIQUE,               -- random hex, in URL t.php?t=...
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE table_sessions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  table_id      INT NOT NULL,
  customer_id   INT NULL,
  session_key   CHAR(32) NOT NULL UNIQUE,            -- stored in customer's cookie for this visit
  status        ENUM('open','bill_requested','closed') NOT NULL DEFAULT 'open',
  bill_no       INT NULL UNIQUE,                     -- combined bill at settlement
  subtotal      DECIMAL(10,2) NULL,                  -- filled at settlement
  discount      DECIMAL(8,2)  NOT NULL DEFAULT 0,
  total         DECIMAL(10,2) NULL,
  opened_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at     DATETIME NULL,
  closed_by     INT NULL,
  KEY idx_table_status (table_id, status),
  FOREIGN KEY (table_id)    REFERENCES dining_tables(id),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (closed_by)   REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Orders and payments
-- ---------------------------------------------------------------

CREATE TABLE orders (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  order_key        CHAR(32) NOT NULL UNIQUE,         -- random, for customer status link
  bill_no          INT NULL UNIQUE,                  -- NULL for table orders (bill is on the session)
  token_no         INT NOT NULL,
  token_date       DATE NOT NULL,
  order_type       ENUM('counter','takeaway','table','bulk') NOT NULL,
  service_type     ENUM('dine_in','parcel') NOT NULL DEFAULT 'parcel',
  customer_id      INT NULL,                         -- NULL for walk-in counter bills
  table_session_id INT NULL,                         -- set for table orders
  staff_id         INT NULL,                         -- who billed / accepted
  status           ENUM('pending_payment','pending_approval','received','preparing',
                        'ready','served','completed','cancelled','no_show') NOT NULL,
  pickup_at        DATETIME NULL,                    -- takeaway slot start / bulk pickup time
  event_date       DATE NULL,                        -- bulk orders
  subtotal         DECIMAL(10,2) NOT NULL,
  parcel_charge    DECIMAL(8,2)  NOT NULL DEFAULT 0,
  discount         DECIMAL(8,2)  NOT NULL DEFAULT 0,
  total            DECIMAL(10,2) NOT NULL,
  advance_amount   DECIMAL(10,2) NULL,               -- bulk: set by owner on approval
  amount_paid      DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_pref     ENUM('online','counter') NOT NULL DEFAULT 'counter',
  note             VARCHAR(255) NULL,
  cancel_reason    VARCHAR(255) NULL,
  cancelled_by     INT NULL,
  accepted_at      DATETIME NULL,
  ready_at         DATETIME NULL,
  completed_at     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_date, token_no),
  KEY idx_status_created (status, created_at),
  KEY idx_type_created (order_type, created_at),
  KEY idx_pickup (pickup_at),
  FOREIGN KEY (customer_id)      REFERENCES customers(id),
  FOREIGN KEY (table_session_id) REFERENCES table_sessions(id),
  FOREIGN KEY (staff_id)         REFERENCES staff(id),
  FOREIGN KEY (cancelled_by)     REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  order_id    INT NOT NULL,
  variant_id  INT NOT NULL,
  item_name   VARCHAR(150) NOT NULL,                 -- snapshot, e.g. "बदाम शेक (फुल) / Badam Shake (Full)"
  unit_price  DECIMAL(8,2) NOT NULL,                 -- snapshot at sale time
  qty         DECIMAL(8,2) NOT NULL,                 -- decimal for kg in bulk orders
  line_total  DECIMAL(10,2) NOT NULL,
  KEY idx_order (order_id),
  FOREIGN KEY (order_id)   REFERENCES orders(id),
  FOREIGN KEY (variant_id) REFERENCES item_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  order_id            INT NULL,                      -- one of order_id / table_session_id
  table_session_id    INT NULL,
  mode                ENUM('cash','upi_counter','upi_online') NOT NULL,
  amount              DECIMAL(10,2) NOT NULL,        -- negative for refunds
  razorpay_order_id   VARCHAR(50) NULL,              -- phase 2
  razorpay_payment_id VARCHAR(50) NULL UNIQUE,       -- phase 2
  status              ENUM('created','success','failed','refunded') NOT NULL DEFAULT 'success',
  staff_id            INT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  FOREIGN KEY (order_id)         REFERENCES orders(id),
  FOREIGN KEY (table_session_id) REFERENCES table_sessions(id),
  FOREIGN KEY (staff_id)         REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE table_alerts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  table_id   INT NOT NULL,
  session_id INT NULL,
  type       ENUM('call_staff','request_bill') NOT NULL,
  status     ENUM('open','done') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  done_by    INT NULL,
  done_at    DATETIME NULL,
  KEY idx_status (status, created_at),
  FOREIGN KEY (table_id)   REFERENCES dining_tables(id),
  FOREIGN KEY (session_id) REFERENCES table_sessions(id),
  FOREIGN KEY (done_by)    REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Owner phone push
-- ---------------------------------------------------------------

CREATE TABLE push_subscriptions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  staff_id   INT NOT NULL,
  endpoint   VARCHAR(500) NOT NULL UNIQUE,
  p256dh     VARCHAR(255) NOT NULL,
  auth_key   VARCHAR(255) NOT NULL,
  is_muted   TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Website content (owner-editable)
-- ---------------------------------------------------------------

CREATE TABLE site_content (
  content_key VARCHAR(50) NOT NULL PRIMARY KEY,      -- about, banner
  value_mr    TEXT NOT NULL,
  value_en    TEXT NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_photos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  file_name  VARCHAR(255) NOT NULL,                  -- in storage/uploads
  caption_mr VARCHAR(150) NULL,
  caption_en VARCHAR(150) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
