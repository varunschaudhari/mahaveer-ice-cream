# Progress

Update at the end of every Claude Code session: tick finished items, note decisions and anything left half-done.

## Milestones

- [x] M0 – Project foundation (2026-11-26, commit a20f8d0)
- [x] M1 – Login, roles, admin menu & settings (2026-11-26 – tests + scripted HTTP checks pass; awaiting developer's browser check)
- [ ] M2 – Counter billing (pilot-ready)
- [ ] M3 – Takeaway ordering + online orders panel
- [ ] M4 – Table ordering
- [ ] M5 – Bulk orders
- [ ] M6 – Owner's phone (PWA + Web Push)
- [ ] M7 – Reports & customers
- [ ] M8 – Public website
- [ ] M9 – Hardening & go-live

## Waiting on the owner

- Prices for Kulfi, Cone, Chocobar, Mango Dolly, Rajberry Dolly, Frosty (seeded inactive at ₹0)
- Logo file, shop/owner/ice cream photos, founding year and family story, Google Maps link
- WhatsApp number (website button stays hidden until set; WhatsApp messaging itself is phase 2)

## Decisions log

| Date | Decision |
|---|---|
| 2026-11-26 | Spec, schema and build plan agreed (see docs/). Razorpay and WhatsApp messaging moved to phase 2. |
| 2026-11-26 | `minishlink/web-push` pinned to `^10.1`. v11 needs a separately installed PSR-18 HTTP client (an extra package); v10 brings Guzzle itself. `chillerlan/php-qrcode` `^6.0`. |
| 2026-11-26 | `composer.json` sets `config.platform.php = 8.2.0` so the lock file never pulls packages that need PHP > 8.2. |
| 2026-11-26 | Money: decimal quantities round half-up to the paisa per line; a discount may not exceed subtotal + parcel; `money()` uses Indian grouping (₹1,25,000). |
| 2026-11-26 | **Pre-M1/M2 answers from the developer** (SPEC, BUILD_PLAN and DEPLOYMENT updated to match): |
| 2026-11-26 | M1: counter login = tap name, then 4-digit PIN (PINs need not be unique); lockout is per account, owner can unlock in admin. No auto-lock; Lock button only. |
| 2026-11-26 | M2: ₹5 parcel charge applies to counter bills marked Parcel (SPEC §5 stands). BUILD_PLAN M2 check corrected to total ₹180 / change ₹20. |
| 2026-11-26 | M2: each new counter bill starts on Dine-in. Save & Print prints 1 copy (customer bill, token on top); no kitchen slip for counter bills. |
| 2026-11-26 | M2: item names on printed bills in Marathi only (shop name and footer stay bilingual). Reprints show "DUPLICATE / प्रत" and are audit logged. |
| 2026-11-26 | Totals round to the nearest ₹1 (50 paise up) after discount; bill shows a "Round off" line when non-zero. Not stored: round off = total − (subtotal + parcel − discount). No schema change. `PricingService::totals()` returns `round_off`. |
| 2026-11-26 | Internet outage: no offline billing in V1. Counter shows a red "No internet – switch on the mobile hotspot" banner and disables Save & Print; phone hotspot / 4G dongle is the backup (DEPLOYMENT §6 checklist item). |
| 2026-11-26 | `migrate.php --fresh` drops all tables instead of the database (cPanel DB users cannot `CREATE DATABASE`). |
| 2026-11-26 | Local dev runs on XAMPP MariaDB 10.4 (docs say 10.6+). Every connection forces MySQL 8's default `sql_mode` (incl. `ONLY_FULL_GROUP_BY`, strict) so local behaves like production. Avoid SQL newer than MariaDB 10.4 / MySQL 8.0. |
| 2026-11-26 | `app/config.php` returns an array, read with `config('KEY')`. Tests use `DB_NAME_TEST` from `phpunit.xml` (must end in `_test`). |
| 2026-11-26 | `website/assets/css/base.css` and the fonts are copies of `order/assets/` (two web roots can't share files); `AssetsSyncTest` fails if they drift. Edit the `order/` copy, then copy it over. |
| 2026-11-26 | CSP is strict (`'self'` only, no inline scripts/styles): pass data to JS via `data-*` attributes or `<meta>` (e.g. `csrf_meta()`), never inline `<script>`. |
| 2026-11-26 | **M1:** counter login works from any device, as the spec says (developer declined device registration). Risk accepted: the login page is public and shows staff names. Mitigation: per-account lock (5 → 15 min) plus max 20 wrong PINs per IP per 15 min (`rate_limits`). Admin password logins: max 10 failures per IP per 15 min. |
| 2026-11-26 | **M1:** login lifetimes – admin (password) 30 days idle, counter (PIN) 12 hours idle. Sessions live in `storage/sessions` with a 31-day cookie refreshed on activity, because PHP's default (24 min, shared `/tmp`) would log the counter out. |
| 2026-11-26 | **M1:** admin always needs a password login, even for an owner already logged in at the counter with his PIN. One user per session; the logged-in user is re-read each request, so deactivation logs out at once. |
| 2026-11-26 | **M1:** menu rows are never deleted (old orders reference variants) – only deactivated. Rule: an active item needs ≥ 1 active variant and no active ₹0 variant. |
| 2026-11-26 | **M1:** owner/super accounts need username + password (min 8 chars); staff need a PIN. Only a super-admin manages super-admin accounts; the last active owner cannot be demoted/deactivated; nobody can deactivate themselves. PINs/passwords never go in the audit log. |

## Session notes

<!-- Newest first. One short entry per session: what was done, what is next, any open problem. -->

### 2026-11-26 – M1 Login, roles, admin menu & settings

- Built `Auth` (name + PIN counter login, password admin login, per-account lockout, per-IP limits, idle limits), `RateLimiter`, `ValidationException`; services `AuditService`, `StaffService`, `SettingsService`, `ImageService`, admin methods in `MenuService`.
- Pages: `order/counter/` (index = name + PIN pad, billing.php placeholder, lock.php), `order/admin/` (index/login, logout, menu, items, settings, staff, audit), `order/media.php`; `admin.css`, `admin.js`, `pinpad.js`; `database/create_owner.php`.
- Checks: 174 PHPUnit tests pass (incl. `LangKeysTest`: every key used in code exists in both languages). Scripted HTTP run on `php -S`: PIN login + countdown + lock + owner unlock, Lock button, admin needs password, Butterscotch Full ₹50 → ₹55 shows on order + website menus and in the audit log, Kulfi ₹0 activation refused with message, photo upload → 1200 px WebP served by media.php, CSRF 403, unauthenticated admin → redirect, 31-day session cookie.
- Local dev DB has test accounts: owner `owner` / `owner-pass-1` (PIN 1111), staff Ramesh (PIN 2222). Butterscotch Full is ₹55 there. Reset with `php database/migrate.php --fresh`.
- Not done (declined in session): `media.php` still sends PHP's session no-cache headers (`Pragma`, `Expires`) next to its long `Cache-Control`, so photos may be re-downloaded more than needed. Harmless; revisit in M8/M9.
- Next: M2 counter billing, after the developer confirms M1. Open item for the M2 plan: `order_items.item_name` snapshot format (see M0 note).

### 2026-11-26 – M0 Project foundation

- Unpacked the kit (docs, schema, seed, .cpanel.yml, .gitignore) into the repo; the zip is gitignored.
- Built `app/` core (bootstrap, helpers, Db, Csrf, Lang, Settings, Migrator), `PricingService`, `MenuService`, shared views, `lang/mr.php` + `lang/en.php`, `database/migrate.php`, base + counter CSS with self-hosted Noto Sans Devanagari, placeholder menu pages for `order/` and `website/`, folder skeleton from ARCHITECTURE §1.
- Checks: `migrate.php --fresh` OK (20 tables, seed counts match), refuses in production; both pages show the menu in Marathi and toggle to English (Kulfi ₹0 hidden); error page/JSON error/logging work; 113 PHPUnit tests pass.
- Answered the 9 pre-M1/M2 questions (see decisions log); added rupee rounding to `PricingService`.
- Open for M2 planning: `order_items.item_name` is one snapshot string ("बदाम शेक (फुल) / Badam Shake (Full)" per the schema comment) but bills print Marathi only. Decide the snapshot format in the M2 plan.
- Local tooling: PHP is `C:\xampp\php\php.exe` (not on PATH); `tools/composer.phar` and `tools/phpunit.phar` (PHPUnit 11.5) downloaded, gitignored.
- To do before M6: enable `gmp`, `intl`, `sodium` in `C:\xampp\php\php.ini`.
- Next: M1 (login, roles, admin menu & settings) after the developer confirms M0.
