# Progress

Update at the end of every Claude Code session: tick finished items, note decisions and anything left half-done.

## Milestones

- [x] M0 – Project foundation (2026-11-26, commit a20f8d0)
- [x] M1 – Login, roles, admin menu & settings (2026-11-26, commit 93624a9)
- [x] M2 – Counter billing (pilot-ready) (2026-11-26, commit 0741a7b; real 1024×768 + TM-T82 print check still open)
- [x] M3 – Takeaway ordering + online orders panel (2026-11-26, commit ed77a87; phone + counter browser check still open)
- [x] M4 – Table ordering (2026-11-26 – tests + scripted HTTP checks pass; awaiting a phone + counter browser check and a KOT print)
- [ ] M5 – Bulk orders
- [ ] M6 – Owner's phone (PWA + Web Push)
- [ ] M7 – Reports & customers
- [ ] M8 – Public website
- [ ] M9 – Hardening & go-live

## Waiting on the owner

- Prices for Kulfi, Cone, Chocobar, Mango Dolly, Rajberry Dolly, Frosty (seeded inactive at ₹0)
- Logo file, shop/owner/ice cream photos, founding year and family story, Google Maps link
- WhatsApp number (website button stays hidden until set; WhatsApp messaging itself is phase 2)
- For bulk orders by weight (M5): which flavours are sold per kg and at what price – the owner adds a "1 किलो / 1 kg" variant to each in admin

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
| 2026-11-26 | **M2 (developer):** sold-out marks reset automatically each new day (first menu/counter request after midnight; last reset date in settings row `stock_reset_date`, audit `stock_reset`). |
| 2026-11-26 | **M2 (developer):** only today's bills can be cancelled (Today tab, owner PIN + reason). Cancelled bills keep their number; their payments become `refunded`; day totals leave them out. |
| 2026-11-26 | **M2 (developer):** counter discount included: ₹ amount + reason, approved immediately by owner PIN (`api/discount_approve.php`, approval held 10 min in the session, used once). Reason + approving owner go to the audit log (`orders` has no discount-reason column). |
| 2026-11-26 | **M2:** `order_items.item_name` stores "मराठी (variant) / English (variant)" as the schema comment shows; bills print the part before " / " (`marathi_name()`). |
| 2026-11-26 | **M2:** double-save protection without a schema change: the counter screen makes the bill's `order_key` before the first save; a repeated key returns the saved bill (`duplicate: true`). The screen also sends the total it showed; if prices changed meanwhile the save is refused and the page reloads. |
| 2026-11-26 | **M2:** payment UI = three buttons (Cash / UPI / Cash + UPI). UPI saves at once; Cash opens a dialog with Exact / next ₹50 / ₹100 / ₹500 / Other and shows the change; Mixed asks for the cash part. Keeps the cart visible at 1024×768 with 56 px buttons. |
| 2026-11-26 | **M2:** sound unlock is a non-blocking "tap anywhere" hint, not a full-screen overlay (each tab is its own page, so an overlay would return on every tab). The counter shortcut gets `--autoplay-policy=no-user-gesture-required` so sound works without a tap (SPEC §9, DEPLOYMENT §6 updated). |
| 2026-11-26 | **M2:** owner PIN approvals check the PIN of any active owner/super-admin; 5 wrong owner PINs per 15 min per address block approvals. |
| 2026-11-26 | **M3 (developer):** customers may cancel their own order from the status page while it is still Received. Reason saved as "ग्राहकाने रद्द केले / Cancelled by customer"; not a no-show. |
| 2026-11-26 | **M3 (developer):** "My recent orders" = this phone's own orders (cookie `mahavir_recent` with id + secret key, last 5), not lookup by mobile (SPEC §6.8 updated). |
| 2026-11-26 | **M3 (developer):** no kitchen ticket for takeaway; staff work from the Online tab card, bill prints at pickup. |
| 2026-11-26 | **M3:** slot capacity counts every order in the slot except cancelled / no_show (picked-up orders still count). The capacity check runs inside the order transaction after taking the daily token row lock, so two phones cannot both get the 10th place. |
| 2026-11-26 | **M3:** "Picked up" is allowed from Preparing or Ready (staff may skip Ready). No discount at pickup for online orders. |
| 2026-11-26 | **M3:** cancel rules in `OrderService::cancel()` – reason always, today only; `received` without the owner, `preparing` / `ready` / `completed` need the owner PIN; `no_show` cannot be cancelled. |
| 2026-11-26 | **M3:** no-show cutoff = slot start + slot_mins + noshow_after_mins (slot 7:30–7:45 → no-show from 8:15). Unblocking a number resets its no-show count. A small admin "Blocked numbers" page (unblock) exists before the full customers page (M7). |
| 2026-11-26 | **M3:** new-order alert: the counter polls `api/poll.php` every 5 s on every tab (replaces `ping.php`). It beeps at once and every 10 s while there is a Received order newer than the last acknowledged one; tapping the alert bar acknowledges (stored in the counter's localStorage) and opens the Online tab; accepting the order also stops it. |
| 2026-11-26 | **M3:** the place-order rate limit (5 per 10 min per IP and per mobile) counts successful orders only. All phones on the shop's own Wi-Fi share one IP – if that becomes a problem, raise the IP limit. |
| 2026-11-26 | **M4 (developer):** any phone scanning a table's QR joins that table's open visit (one running bill per table); each new phone gives name + mobile once (remembered on the phone per visit, SPEC §7.2 updated). |
| 2026-11-26 | **M5 (developer, decided now):** bulk orders by weight use "1 किलो / 1 kg" variants the owner adds in admin (they also show as family packs on menu + counter); staff handle approved bulk orders on a new counter Bulk tab; the owner approves / edits / rejects in admin. |
| 2026-11-26 | **M4:** a table visit = a `table_sessions` row, opened by the first order (table row locked so two phones share one visit), closed at settlement with ONE bill number; table orders keep `bill_no` NULL and never pay parcel. Settling completes every non-cancelled order (confirmation if some are unserved), clears the table's alerts, records payments on `payments.table_session_id`. A visit with nothing billable (all cancelled / moved away) closes without a bill; cancelling or moving the last order does this automatically. |
| 2026-11-26 | **M4:** table discounts use the same owner-PIN approval as counter bills. A settled table bill is cancelled from the Today tab with owner PIN (today only): its orders become cancelled, its payments refunded, the visit keeps its bill number. A single order of a settled visit cannot be cancelled on its own. |
| 2026-11-26 | **M4:** the day summary now counts money by payment time (`payments.created_at`, set explicitly by `PaymentService::record`), bills by the time they were issued (orders `completed_at`, visits `closed_at`), so bulk advances (M5) land on the day they were paid. |
| 2026-11-26 | **M4:** Call staff / Request bill: max one alert per table per 30 s (checked against `table_alerts.created_at`); a button still open is not duplicated; customers may keep ordering after Request bill. Alerts join the counter's alert bar + beep (ack stored as newest order id + newest alert id). |
| 2026-11-26 | **M4:** `BillFormatter` builds what a bill prints from an order or a table visit, so one template prints both; table bills show "टेबल 4" in place of the token and merge the same item from several orders into one line. KOT prints on Accept of a table order only (no prices, table number 44 px). |

## Session notes

<!-- Newest first. One short entry per session: what was done, what is next, any open problem. -->

### 2026-11-26 – M4 Table ordering

- Services: `TableService` (visits, running total, combined lines, alerts, move, settle, close-if-empty, cancel settled bill, regenerate QR, overview for tiles), `BillFormatter`; `OrderService::placeTableOrder` / `markServed`, accept for table orders, table cancel rules; `PaymentService::record` takes the payment time (+ table visit); `ReportService::daySummary` by payment / bill time incl. table bills.
- Customer: `order/t.php` (banner "टेबल 4 / Table 4", table's orders with live status, Call staff / Request bill, menu, checkout without slot or parcel), `table.js`, table mode in `shop.js`; menu and checkout markup moved to partials `shop_menu` / `shop_checkout` (used by the takeaway page too). API: `place_table_order`, `table_status`, `table_alert`.
- Counter: Tables tab (`tables.php`, `tables.js`: tiles + detail dialog with Accept / Served / Move / Cancel, dismiss alerts, discount, Settle & Print, free an empty table); Online tab shows table orders with a large "TABLE 4" and Accept → KOT print, Served; alert bar + beep + Tables badge for table alerts; Today tab lists settled table bills (reprint, cancel with owner PIN). API: `table_detail`, `table_action`; `order_action` gains `serve`; `poll` gains `view=tables` and alert counts. Print: `print.php?type=table|kot`, `print_kot` view.
- Admin: Tables page (active, regenerate QR, open page), A4 sticker sheet (2 × 4).
- Checks: 271 PHPUnit tests pass. Scripted HTTP run: two phones at T4 → one visit, running total ₹255; counter sees "TABLE 4", Accept → KOT (table number, items, note, no prices), Served shows on the table page; Call staff on T7 → alert count + T7 tile alert (Outside), second tap within 30 s refused; Request bill → tile "Bill requested"; Settle → bill 1, combined (3 × Badam Shake), T4 free; move T5 → T6; regenerated T4 QR → old link shows the friendly error, new one works; sticker sheet renders; Today tab table bill → reprint DUPLICATE → cancel needs owner PIN → day total ₹255 → ₹0; no errors in the app log. (Devanagari typed in curl arguments arrives as "?" in Git Bash – a test-shell issue, not the app; sending a UTF-8 file body printed the note on the KOT correctly.)
- Not verified here: phone + Chrome 109 counter in a real browser, KOT/bill on the TM-T82. The local dev DB holds test data from the scripted run.
- Next: M5 (bulk orders) after the developer confirms M4.

### 2026-11-26 – M3 Takeaway ordering + online orders panel

- Services: `SlotService` (slot times, capacity), `CustomerService` (upsert by mobile, no-shows, block/unblock), `QrService`; `OrderService` gains placeTakeaway, accept, markReady, pickup, cancelByCustomer, markNoShows, activeOnline, newOnlineSummary and the wider cancel rules; `RecentOrders` cookie; helpers `normalize_mobile`, `line_name`, `order_status_label`.
- Customer: `order/index.php` (menu, cart bar, checkout with slots, recent orders + reorder, closed banner), `order/order.php` status page (10 s polling, self-cancel), `shop.js`, `status.js`, `shop.css`. API: `place_order`, `slots`, `order_status`, `customer_cancel`.
- Counter: Online tab (`online.php`, `online.js`), `poll.php` every 5 s on all tabs (badge, alert bar, beep loop) replacing `ping.php`; cash dialog moved to the shared frame (`Counter.cash`, `Counter.pay`); Today tab shows takeaway orders and their status. `order_action` gains accept / ready / pickup; cancel PIN optional for Received orders.
- Admin: A4 takeaway QR poster (`admin/qr.php`, warns when not on production), Blocked numbers (`admin/blocked.php`). Cron: `cron/auto_noshow.php`.
- Checks: 256 PHPUnit tests pass. Scripted HTTP run: order from the menu page → poll shows it → Accept / Ready / Picked up (bill 1, ₹165, customer page follows each step) → bill prints "टेकअवे"; customer self-cancel (wrong key refused); accepted order needs the owner PIN to cancel; 6th order from one IP in 10 min refused; 11th order in a slot refused and the slot reports full; two uncollected orders → cron marks no-show → number blocked → admin Unblock → can order again; closed banner when past closing; recent orders + reorder on the phone; QR poster renders; no errors in the app log. All JS files pass `node --check`; no post-Chrome-109 features in counter JS/CSS.
- Not verified here: the pages in a real phone browser and in Chrome 109 on the counter (tap flows, sticky cart bar, dialogs, beep). The local dev DB holds test orders from the scripted run (reset with `migrate.php --fresh` + `create_owner.php`).
- Next: M4 (table ordering) after the developer confirms M3.

### 2026-11-26 – M2 Counter billing

- Services: `SequenceService` (daily token, continuous bill no, row locks, must run in a transaction), `CartService` (DB prices, active/₹0/sold-out/qty 1–50 checks; counter vs online channel), `PaymentService` (cash/UPI/mixed split, change), `OrderService` (createCounterOrder, cancel, logReprint, find, forDay), `ReportService::daySummary`; `MenuService::counterMenu/setAvailability/resetStockIfNewDay`; `Auth::verifyOwnerPin`, `Auth::user(touch: false)`.
- Counter: `billing.php`, `stock.php`, `today.php`, `print.php` (bill + day summary, 80 mm `print.css`), shared frame (tabs, offline banner, toast, number pad + reason dialogs, hidden print iframe), `counter.js` / `billing.js` / `stock.js` / `today.js`. API: `counter_bill`, `discount_approve`, `stock`, `order_action` (reprint, cancel), `ping`.
- Checks: 221 PHPUnit tests pass. Scripted HTTP run on `php -S`: the M2 example bill = ₹180 (token 1, bill 1, Marathi-only names, parcel line); repeated key returns the same bill; stale price refused; UPI + mixed bills; wrong / missing / used-up discount approvals refused, ₹10 discount printed; sold-out Mango refused then restored; reprint marked DUPLICATE; cancel needs owner PIN, second cancel refused; day summary cash ₹200 / UPI ₹60 / total ₹260 / 3 bills (cancelled left out); ping 401 after Lock; no errors in the app log. No post-Chrome-109 features in counter/print CSS or JS.
- Not verified here (needs the developer / shop): the layout in a real 1024×768 Chrome window, and printing on the Epson TM-T82 with `--kiosk-printing` (bill width, Marathi rendering, token size).
- Local dev DB now has a few test bills from the scripted run (bill 1 cancelled). Reset with `php database/migrate.php --fresh` and re-run `create_owner.php`.
- Next: pilot deploy of M0–M2 to staging (DEPLOYMENT.md), then M3 after the developer confirms M2.

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
