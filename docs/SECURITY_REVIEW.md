# Security review (M9)

ARCHITECTURE §11 checked line by line on 2026-11-26, against the code at the end of M9. "How checked" says what was actually run. Scripts used for the scans are described so they can be re-run before later releases.

| # | Checklist item | Result | How checked |
|---|---|---|---|
| 1 | PDO prepared statements only; no string-built SQL with user input | **Pass** | Tokenizer scan of every `->query()` / `->prepare()` / `->exec()` whose SQL contains a variable: 19 hits. All are `?` placeholder lists, integer `LIMIT`s, internal SQL fragments built from code constants (`ReportService::bills()`, `CustomerService::where()`), column names written in the code (`OrderService::transition()`), or table names from `information_schema` (Migrator, backup), backtick-escaped. Every user value is a bound parameter. LIKE searches escape `%` / `_`. |
| 2 | `e()` on every output; JSON with `JSON_HEX_TAG \| JSON_HEX_AMP` | **Pass** | Tokenizer scan of every `<?=` / `echo` in pages and views not wrapped in `e()`. Every hit is an int, a fixed string chosen by a ternary, a constant, or HTML built from `e()`-escaped parts (`$csvLink`, `$blockForm`, `items.php` `$n`). JSON into pages: `e(json_encode(…))` in `data-*` attributes, `json_response()` and the JSON-LD block use the HEX flags. JavaScript never puts data into `innerHTML` (two fixed templates, data via `textContent`). |
| 3 | CSRF on all POSTs | **Pass** | All 25 files that read `$_POST` / `json_body()` / check `is_post()` call `Csrf::check()`. Every endpoint without a POST check only reads data (`order_status`, `poll`, `slots`, `table_detail`, `table_status`, `print`, `media`). |
| 4 | `password_hash()` / `password_verify()` for PINs and passwords; new session id on login | **Pass** | `StaffService` hashes PINs and passwords with `PASSWORD_DEFAULT`; `Auth` verifies with `password_verify` (dummy hash for unknown usernames, so timing does not reveal them) and calls `session_regenerate_id(true)` on login and logout. Sessions: strict mode, HttpOnly, SameSite=Lax, Secure off `local`. |
| 5 | Random 32-char `order_key` / `qr_token`; never ids alone | **Pass, one go-live step** | Order keys come from `crypto.getRandomValues` in the browser (checked against `^[a-f0-9]{32}$`, compared with `hash_equals`); regenerated table tokens use `random_bytes(16)`. **But** the seeded table tokens come from MySQL `MD5(UUID(), RAND())`, which is not a strong random source → before printing stickers in production, regenerate every table's QR in admin → Tables (GO_LIVE.md step 4). |
| 6 | Validate mobile, names (1–60), quantities (1–50; bulk ≤ 999.99), notes (≤ 200) | **Pass** | `CustomerService::validateContact()` (name ≤ 60, mobile `^[6-9]\d{9}$` after removing +91 / spaces, note ≤ 200, no control characters), `CartService` (`MAX_QTY` 50, `MAX_LINES` 50, bulk 0.01–999.99). Covered by `CartServiceTest`, `TakeawayOrderTest`, `BulkOrderTest`. |
| 7 | Server recalculates every price and total | **Pass** | `CartService` reads prices from the database; the browser sends variant ids and quantities only. The counter also sends the total it showed and the save is refused if prices changed meanwhile. |
| 8 | Owner-only actions re-check the role on the server | **Pass** | Every `order/admin/*` page and `owner_action` / `push_subscribe` call `Auth::requireAdmin()` (password login, owner or super); `poll.php?view=owner` checks `Auth::isAdmin()`; counter pages and staff APIs call `Auth::requireStaff()`; cancel-after-accept and discounts need `Auth::verifyOwnerPin()`. System health additionally requires the `super` role. Public endpoints are only the customer ones (order key / table token checked). |
| 9 | `app/`, `storage/`, `database/`, `cron/`, `tests/`, `vendor/` never web-accessible | **Pass** | Only `order/` and `website/` are web roots; `.cpanel.yml` copies the rest to `$APPDIR` beside them (`DeployConfigTest`). `/cron/backup.php` from the order server → 404. Cron scripts also refuse non-CLI calls. |
| 10 | Security headers incl. a basic CSP | **Pass (+ added)** | `send_security_headers()`: nosniff, `Referrer-Policy`, `X-Frame-Options`, strict CSP (`'self'` only, no inline scripts / styles, frames only Google Maps on the location page once set). **Added in M9:** `Permissions-Policy`, HSTS (production over HTTPS only), `X-Powered-By` removed. Checked over HTTP on order pages, an API and website pages. |
| 11 | Uploads: jpg/png/webp only, MIME via `finfo`, re-encoded by GD, random names | **Pass** | `ImageService`: `finfo` + `getimagesize`, ≤ 5 MB, ≤ 40 MP, rotation fixed, re-encoded to WebP (strips anything hidden), 32-hex random name; `media.php` only serves names matching that pattern (path tricks → 404). |

## Also checked

- **Brute force:** per-account lock (5 wrong → 15 min), per-IP limits (20 PINs, 10 passwords, 5 owner PINs per 15 min), order / bulk / table-alert rate limits.
- **Open redirects:** `?next=` accepts only `/owner/`; the customers page `back` only `/admin/customers.php…`; the language switch redirects to the current path only.
- **Clickjacking:** `X-Frame-Options: SAMEORIGIN` + `frame-ancestors 'self'`.
- **CSV / Excel formula injection:** text starting with `= + - @` (e.g. a customer name) gets a leading apostrophe (`CsvTest`).
- **Errors:** `display_errors` off; details only when `APP_ENV=local`; everything logged to `storage/logs`. No PINs or passwords in logs or the audit log.
- **Secrets:** `app/config.php` (DB password, VAPID private key) is gitignored and never deployed; the backup cron passes the DB password in a 0600 temporary file, not on the command line.
- **Map embed:** only `https://www.google.com/maps/embed?pb=…` addresses are kept from what the owner pastes.

## Manual go-live items (cannot be done in code)

- cPanel → Indexes → **No Indexing** for `public_html` and the order subdomain (no folder listings of `/assets/`).
- cPanel → MultiPHP INI Editor: `expose_php = Off`, `display_errors = Off` (the app sets them too; belt and braces).
- Regenerate all table QR tokens in production before printing stickers.
- Use strong passwords for the owner and developer logins (min 8 characters is enforced; use more).

## Re-running the scans

The two token scans (SQL with variables, unescaped output) are small PHP scripts using `token_get_all()`; they are not part of the repo. To re-check quickly: `grep -rn "Csrf::check" order` (one per POST-handling file), and review any new `->query(` / `->prepare(` and `<?=` lines in a diff.
