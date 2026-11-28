-- 003: print queue for the counter used from a phone (decision 2026-11-28).
-- The receipt printer hangs on the counter PC (USB, Chrome --kiosk-printing). A phone that takes a
-- payment on the counter screen queues the bill here; the counter PC claims and prints it within
-- a few seconds. Only what print.php can print: an order's bill, a table visit's bill, the day
-- summary. Kitchen tickets are not queued (the counter PC prints them itself from the poll).
-- No existing table changes.

CREATE TABLE print_jobs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  job_type    ENUM('bill','table','day') NOT NULL,
  ref_id      INT NULL,                          -- orders.id (bill) or table_sessions.id (table); NULL for day
  is_copy     TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = reprint, printed as DUPLICATE
  created_by  INT NULL,                          -- staff who sent it
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at  DATETIME NULL,                     -- when a counter PC took it to print
  claim_token CHAR(32) NULL,
  KEY idx_unclaimed (claimed_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
