# Progress

Update at the end of every Claude Code session: tick finished items, note decisions and anything left half-done.

## Milestones

- [x] M0 – Project foundation (2026-11-26, commit a20f8d0)
- [x] M1 – Login, roles, admin menu & settings (2026-11-26, commit 93624a9)
- [x] M2 – Counter billing (pilot-ready) (2026-11-26, commit 0741a7b; real 1024×768 + TM-T82 print check still open)
- [x] M3 – Takeaway ordering + online orders panel (2026-11-26, commit ed77a87; phone + counter browser check still open)
- [x] M4 – Table ordering (2026-11-26, commit ebee6f8; phone + counter browser check and a KOT print still open)
- [x] M5 – Bulk orders (2026-11-26, commit f05b019; browser check still open)
- [x] M6 – Owner's phone (PWA + Web Push) (2026-11-26, commit d764b3e; "Done when" needs an Android phone on staging, see DEPLOYMENT §7)
- [x] M7 – Reports & customers (2026-11-26, commit c7f3e90; opening a CSV in Excel is left to the developer)
- [x] M8 – Public website (2026-11-26, commit adc8784; the Lighthouse run itself is left to the developer, see DEPLOYMENT §8)
- [ ] M9 – Hardening & go-live (code done 2026-11-26, commit 18597e5: security review, backup / cleanup crons, System health, deploy config; the go-live itself – staging, production, printing, the first real evening – follows docs/GO_LIVE.md)

## Waiting on the owner

- Prices for Kulfi, Cone, Chocobar, Mango Dolly, Rajberry Dolly, Frosty (seeded inactive at ₹0)
- The designer's original logo file (vector or a large PNG; we only have a 1048×771 JPG, so the app icons are a little soft), shop/owner/ice cream photos, founding year and family story, Google Maps link
- WhatsApp number (website button stays hidden until set; WhatsApp messaging itself is phase 2)
- For bulk orders by weight (M5): which flavours are sold per kg and at what price – the owner adds a "1 किलो / 1 kg" variant to each in admin
- Menu text check: some seeded Marathi size labels use Devanagari digits (e.g. Shrikhand "५०० ग्रॅम"), while SPEC §15 says Western digits everywhere. Fix in admin → Menu if the owner agrees (seed data, not code).

## Decisions log

| Date | Decision |
|---|---|
| 2026-11-26 | Spec, schema and build plan agreed (see docs/). Razorpay and WhatsApp messaging moved to phase 2. |
| 2026-11-27 | **Client:** "modern gelato boutique" look for the website + customer ordering pages; new logo (concept C "scoop heart", docs/brand/); English name **Mahaveer** (seed `shop_name_en`, English page titles; staging: change it in admin → Settings). SPEC §16 updated. Founding year for the seal still to come. |
| 2026-11-27 | **Client (later the same day):** use the shop's own logo (supplied as a JPG) instead of the scoop heart, which is retired (still in git history). English name back to **Mahavir** and tagline "Adding flavors to your life", both as on the logo. Accent colour = logo pink, pastels from the logo colours, black buttons kept. App icons and notification badge from the logo's swirl-and-M. SPEC §16 updated. Staging: set the English shop name back to "Mahavir Ice Cream" in admin → Settings. |
| 2026-11-27 | **Developer (usability review):** takeaway checkout selects the earliest free pickup slot already (customer can change it); pickup time is asked before name/mobile. Customer menu as compact rows (no letter tiles). Counter: an open online order whose pickup window has ended is marked "Late"; on the Online / Tables tab the alert bar leaves out what is already on screen, the beep repeats until any tap there. SPEC §6, §9 updated. |
| 2026-11-27 | **Developer:** an Android **owner app** (APK, installed from the file – the Play Store app stays phase 2). It is a Trusted Web Activity around `/owner/` (androidbrowserhelper 2.7.4), so it needs no own code and follows every website deploy. Package `com.mahaveericecream.owner` (`in.` is not allowed: Java keyword). Built for staging now, rebuilt for production at go-live (GO_LIVE §3). Source in `android/`; signing key outside the repo. DEPLOYMENT §7b. |
| 2026-11-28 | **Client:** the counter also works on phones (owner from the admin menu). A phone never prints kitchen tickets; bills taken on a phone print at the counter PC through a print queue (new table `print_jobs`, migration 003, approved). Per-device "This screen prints bills" setting (Today tab), on by default on wide screens. |
| 2026-11-28 | **Client (owner):** takeaway orders are **paid first by UPI, without Razorpay or any gateway**. All takeaway orders (when a UPI ID is set in Settings; empty = pay at the counter as before); the counter confirms each payment on its soundbox / UPI business app; the customer gives no proof ("I have paid" only). Defaults by the developer: 15 min to pay (setting), then auto-cancel unless "I have paid" was tapped; "Payment received" goes straight to Preparing; the owner is notified once paid; customer cancel only before "I have paid"; cancelling a paid order = owner PIN + refund from the owner's UPI app. No schema change (`pending_payment`, `payment_pref = online`, `payments` status `created` → `success`). SPEC §3, §6.10, §9, §12, §14; CLAUDE.md out-of-scope line now "any payment gateway". |
| 2026-11-28 | **Developer:** staging QR poster / stickers are scannable, so the whole flow can be tested with real phones. They carry a dashed "TEST (staging) – not for customers" band (poster: above and below the code; sticker: below it, in place of "Scan to order") and a staging notice on screen. Production unchanged (clean codes); local / http keeps the stamp across the code. `QrService::mode()`. |
| 2026-11-28 | **Fix:** counter pages now send CSP `frame-src 'self'`. Since M0 every page sent `'none'`, which blocked the hidden frame the counter prints through, so bills / KOTs could not have printed on the counter PC (never tested on the real printer). |
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
| 2026-11-26 | **M5:** bulk request = order_type `bulk`, status `pending_approval`, token on the request day, `event_date` + `pickup_at`, decimal quantities 0.01–999.99 (`CartService` channel `bulk`, which ignores today's sold-out marks), no parcel charge, totals rounded to the rupee. Event date ≥ today + bulk_notice_days and ≤ 90 days; pickup time within shop hours. 3 requests per hour per IP and per mobile. |
| 2026-11-26 | **M5:** owner (admin): edit lines (qty, price per line; a blank price on a new line = menu price), approve with an advance (₹0 … total) → `preparing`, reject / cancel with a reason that the customer sees; an approved order's payments become `refunded` on cancel. A new total may not drop below what was already paid. Audit: bulk_approve / bulk_reject / bulk_update / cancel_order. |
| 2026-11-26 | **M5:** staff (counter Bulk tab): record payments before pickup (cash / UPI, ≤ balance) → `orders.amount_paid`; Ready; Picked up collects the balance (cash / UPI / mixed, nothing if fully prepaid) → bill number → bill shows event date, "आगाऊ भरले" and "आत्ता भरले". Customer self-cancel works while the request is still pending. |
| 2026-11-26 | **M5:** the customer status page (`order.php`) serves takeaway and bulk; label / help / cancel come from `customer_status()` so the page and its 10 s refresh agree (e.g. the advance due after approval). |
| 2026-11-26 | **M6 (developer):** a table order accepted on the owner's phone still prints its KOT at the counter automatically. The counter's 5 s poll gets `kot_pending` (table orders accepted in the last 10 min) and prints each once; printed ids are kept in the counter's localStorage, so Accept at the counter does not print twice. The counter must be open for this. |
| 2026-11-26 | **M6 (developer):** notifications use the language the owner page uses on that phone. Each push carries Marathi + English; the service worker picks one from Cache Storage (written by the owner page on every load, Marathi if missing). |
| 2026-11-26 | **M6:** the owner page needs the owner's password login (as admin); `/admin/?next=/owner/` returns there after login (`/owner/` is the only allowed `next`). Actions on the phone need no PIN: the password login is the owner's approval (cancel of an accepted order is logged with the owner as approver). |
| 2026-11-26 | **M6:** on the phone the owner can Accept takeaway / table orders, Cancel any open order with a reason (preset list or own text), Approve a bulk request with an advance (rupees, up to 2 decimals) and Reject / cancel bulk orders. Ready / Served / Picked up / payments stay on the counter. |
| 2026-11-26 | **M6:** pushes go to unmuted phones of active owners / super-admins only. Sent after the reply (`notify_owners_later()`): new takeaway, table, bulk order (not for a repeated order key) and a *new* Request bill (not while the same alert is still open). Push service TTL 15 min, urgency high; 404/410 deletes the phone's subscription. A "Send a test" button sends to this phone only (even when muted). |
| 2026-11-26 | **M6:** placeholder app icons (pink square, ice-cream cone, drawn with GD) until the logo arrives: `assets/img/icon-192.png`, `icon-512.png`, `icon-512-maskable.png`, `badge-72.png` (white silhouette for the Android status bar). `owner/manifest.json` app name is Marathi ("महावीर आईस्क्रीम – मालक"), being the shop's name rather than UI text. |
| 2026-11-26 | **M6:** `sw.js` caches only the offline page (both languages) and its CSS; if that fails the worker still installs, so notifications never depend on it. The owner page re-sends its subscription on each load and re-subscribes by itself if the server's VAPID key changed. |
| 2026-11-26 | **M7 (developer):** top flavours are ranked by amount (₹), all sizes of an item together; quantity is shown alongside (Half, Full, 1 kg each count as 1). Print = an A4 page from the browser (can be saved as PDF), no 80 mm range summary. |
| 2026-11-26 | **M7 (developer):** the owner may download the customer list (names + mobile numbers) as CSV, with the current search / filter; every download is written to the audit log (`customers_export`: count, filter, search). |
| 2026-11-26 | **M7:** report bases = the day close's: money by payment time; bills, item sales, staff-wise and billed amounts by bill time (orders `completed_at`, table visits `closed_at`), cancelled bills left out and listed separately; order counts by token date (not cancelled / no-show). `daySummary()` is now `summary()` for one day, so a one-day report equals the day close by construction; tests also cross-check the per-day report against it. |
| 2026-11-26 | **M7:** item-wise = per size (variant) in menu order with today's menu names; amounts are line totals before discount, parcel charge and round-off (the page says so). Staff-wise = who issued the bill (counter biller, pickup, bulk completion) or settled the table. Cancelled bills show the first order's reason and who cancelled. |
| 2026-11-26 | **M7:** ranges are whole days, both ends included, presets Today / Yesterday / This week (from Monday) / This month / Last month, or custom up to 366 days (bad or too long → today with a message). CSV: UTF-8 BOM, CRLF, amounts as rupees "1600.00", dates Y-m-d, text starting with = + - @ gets a leading apostrophe (names are typed by customers). |
| 2026-11-26 | **M7:** Customers replaces the M3 "Blocked numbers" page (old URL redirects to the Blocked filter). Search: digits search the mobile ("+91 98765 43210" works), anything else the name (`%` / `_` are literal). The owner can also block a number by hand (audit `customer_block`); unblocking still resets the no-show count. Blocking stops online takeaway orders only, as before. |
| 2026-11-26 | **M8 (developer):** the Google Map on the location page loads only when tapped: address, Directions button and a map placeholder first; nothing is requested from Google before the tap. The owner pastes Google's "Embed a map" code once in Settings (`google_maps_embed`); only a `https://www.google.com/maps/embed?pb=…` address is kept, and the location page's CSP allows frames from `https://www.google.com` only while one is set. No migration: settings are saved with an upsert, so the row appears on first save (a missing row counts as empty, so other saves don't log a change). |
| 2026-11-26 | **M8 (developer):** home page best sellers are automatic: top 3 items by sales amount over the last 30 days (same ranking as the M7 top-flavours report), only items on the online menu now; Badam Shake, Mahavir Special, American Dryfruit (SPEC §13) fill empty places. No admin picker. |
| 2026-11-26 | **M8:** the public website starts no session (`APP_NO_SESSION` in `website/boot.php`): no cookie on every visit, no session file per crawler, photos from `website/media.php` stay cacheable. Language toggle unchanged (cookie), so only the Marathi pages are indexed; titles carry both "महावीर आईस्क्रीम, अमळनेर" and "Ice Cream Amalner" so either search matches. Non-production pages carry `noindex`. |
| 2026-11-26 | **M8:** pages = `SiteService::PAGES` (home, menu, about, bulk, gallery, location, contact); the bulk page is `website/bulk.php` (ARCHITECTURE had `bulk-orders.php`, which also breaks the snake_case rule). `sitemap.xml` is a static file with production URLs kept right by `SiteFilesTest` instead of an admin "regenerate" button (pages only change with code). ARCHITECTURE updated. |
| 2026-11-26 | **M8:** hero = the first visible gallery photo (`fetchpriority="high"`, width/height from the file); without photos a brand-colour placeholder with a cone drawing. JSON-LD `IceCreamShop` on home / location / contact: names mr + en, phone, address (postcode from the address, locality Amalner), daily hours from Settings, map link, and geo read from the embed code (`!2d<lng>!3d<lat>`). |
| 2026-11-26 | **M8:** admin → Website content: About (mr/en, up to 5,000 characters each, blank line = new paragraph, shown as text, never HTML), banner (mr/en, one line up to 200, on/off, shown on every page), gallery (upload through the same 1200 px WebP pipeline, captions mr/en, order, show/hide, delete removes the file). All audit-logged. |
| 2026-11-26 | **M9 (developer):** backups stay on the server (30 nightly copies in `storage/backups`) plus the host's own account backups; nothing is emailed, so customer data never leaves the server automatically. GO_LIVE.md asks to check the hosting plan has account backups, to download a copy now and then, and to test-restore once before go-live. |
| 2026-11-26 | **M9 (developer):** admin → System health for the super-admin only (owner gets 403, no nav link): cron last runs, latest backup, today's errors / warnings with the last lines, pending migrations, PHP / DB versions, APP_ENV, HTTPS, display_errors, push keys, disk, writable storage. Crons record their last run in `storage/cron/<name>.json` via `App\Cron` (also: lock against overlapping runs, exit 1 on failure). |
| 2026-11-26 | **M9:** security review (docs/SECURITY_REVIEW.md): all 11 ARCHITECTURE §11 items pass. Added `Permissions-Policy`, HSTS (production over HTTPS only, 1 year, no subdomains), removed `X-Powered-By`. Finding: seeded table QR tokens come from MySQL `MD5(UUID(), RAND())` → regenerate every table's QR in production before printing stickers (GO_LIVE.md step 4). Off production the QR poster / stickers print a "TEST – DO NOT USE / चाचणी – वापरू नका" stamp across every QR. |
| 2026-11-26 | **M9:** backup = mysqldump via `proc_open` (password in a 0600 temporary options file; `--single-transaction --no-tablespaces`; errors to a file) gzipped by PHP, falling back to a PHP dump (consistent snapshot, rows streamed unbuffered) when mysqldump cannot run; written as `.part` then renamed; old ones pruned only after a good backup. Both restore checked: all 20 tables identical by CHECKSUM. Cleanup: logs > 90 days, rate_limits > 24 h, sessions > 31 days, unused photo files > 7 days. |
| 2026-11-26 | **M9 (schema, approved):** migration `002_report_indexes.sql` adds `orders(completed_at)` and `table_sessions(closed_at)` indexes for the reports / day close. No column or table changes. |
| 2026-11-26 | **M9:** `.cpanel.yml` copies `order/` with `--delete` (pages removed from the code disappear; cPanel's `.well-known`, `.htaccess`, `cgi-bin`, `.user.ini`, `php.ini`, `error_log` protected); `public_html` stays add / update only; creates `storage/sessions` and `storage/cron` too. `DeployConfigTest` guards what is deployed. |

## Session notes

<!-- Newest first. One short entry per session: what was done, what is next, any open problem. -->

### 2026-11-28 – Pay first by UPI (takeaway)

- Settings: `upi_id` (checked, saved in lower case) and `payment_wait_mins` (5–60, default 15), new group "Online payment (UPI)". `SettingsService::DEFAULTS` covers keys added after the seed (no migration; a missing key keeps its value).
- `UpiService`: on/off, UPI ID check, `upi://pay` link (pa, pn, am, cu, tn – no `tr`; "@" left unencoded).
- `OrderService`: takeaway placed as `pending_payment` when on; `customerSaysPaid`, `confirmPayment` (→ preparing, payment success, audit `payment_confirmed`), `paymentMissing`, `cancelUnpaid` (cron `auto_noshow.php`, audit `cancel_unpaid`), `isPrepaid` / `hasPaymentClaim` / `payBy`; pickup records no second payment; staff may cancel `pending_payment` without the owner; cancelling marks unchecked claims `failed`. `activeOnline` includes waiting orders; `newOnlineSummary` adds `pay_count` / `latest_pay_id`.
- Customer: checkout line "Pay by UPI right after…"; status page payment card (Pay with a UPI app, I have paid, folded QR + UPI ID + Copy), steps Payment → Preparing → Ready → Picked up, reloads when the counter decides; `api/customer_paid.php`.
- Counter: Online tab waiting / check-payment / paid cards (`online.js`, `counter.css`), third alert kind `p` in `counter.js` (beep + badge + alert bar "Payments to check"); `api/order_action.php` `confirm_payment` / `payment_missing`; bill and Today tab label "Online UPI". Owner's phone shows waiting orders read-only; push only after the payment is confirmed.
- Checks: 380 PHPUnit tests pass (new `PrepayTest`, 13 tests). Locally end to end in headless Chrome: Settings saved the UPI ID → order → payment card (390 px, Marathi + English, QR open) → I have paid → "Checking payment" → counter card "Check payment" + confirm dialog → Preparing + "Paid by UPI" → Picked up without payment dialog → bill "ऑनलाइन UPI ₹125"; waiting card at phone width; customer cancel of an unpaid order; cron run.
- Not testable here: real UPI apps opening the link (GPay / PhonePe / Paytm / iPhone) with the shop's business UPI ID – GO_LIVE §2.
- Next: deploy to staging, owner enters the real UPI ID in Settings, test with real phones and a small real payment.

### 2026-11-28 – Scannable QR codes on staging (TEST band)

- `QrService::mode($env, $url)`: `live` (production + https), `test` (staging + https), `blocked` (everything else).
- `admin/qr.php` and `admin/table_stickers.php` use it:
  - test: a clean code, a dashed band "चाचणी (स्टेजिंग) – ग्राहकांसाठी नाही · TEST (staging) – not for customers" (`.test-band` in `poster.css`), and an on-screen staging notice
  - blocked: the stamp across the code, as before
- On a sticker the band sits below the code, in place of "Scan to order" (with both, the text ran past the sticker's 68 mm border).
- New text keys: `qr.test_band`, `qr.staging_notice`.
- Checks: 367 PHPUnit tests pass (new `QrServiceTest`). Print preview rendered for the poster and stickers in test mode: everything inside the sticker border.
- Next: deploy to staging, print the poster and a sticker sheet, test takeaway and table orders end to end with real phones (counter, owner app notifications, printing).

### 2026-11-28 – Counter on phones + print queue

- "Counter" added to the shared owner/admin menu (second, after Live orders).
- Phone layout for the counter (below 1024 px; the counter PC at ≥ 1024 px is unchanged), in `counter.css` (end of file):
  - compact header (icons for admin / lock, avatar without name); tabs as a sideways row that starts at the current tab
  - billing: items full width, the bill as a bottom bar ("3 items · ₹95 · View bill") opening the bill as a sheet that closes after payment (`billing.js`)
  - online / bulk cards one per row, tables two per row, stock two per row; Today's bills as cards
  - login: name tiles, then the PIN card (scrolled into view)
  - the sound hint at the top
- Printing:
  - `PrintQueueService` + `api/print_queue.php` (add / claim) + `print_queue` count in the poll + migration `003_print_jobs.sql` + weekly cleanup
  - `counter.js`: `isPrinter()` (Today tab setting, default by width). Non-printing screens queue bills and skip KOTs; the printing screen claims and prints them silently.
- Fixed the CSP that blocked counter printing (`frame_sources_for()`, tested).
- New text keys: `counter.print_sent`, `counter.printer_device`, `counter.printer_hint`, `counter.view_bill`, `print.error.invalid`, `nav.open_menu` / `nav.close_menu` (earlier today).
- Checks: 365 PHPUnit tests pass (new `PrintQueueTest`). Headless Chrome:
  - 390 px: every counter tab, the bill sheet, login
  - 1024×768: billing unchanged
  - end to end: a cash bill paid at phone width was queued (toast "Bill sent to the counter printer"); the counter at PC width claimed it and loaded `print.php?type=bill` in its print frame
- Not testable here: the actual paper print on the TM-T82 (GO_LIVE §2).
- Real phone (Samsung Galaxy M14, 384 px wide, owner login): the header pushed the counter wider than the screen (the shop name could not shrink). Fixed: the name shortens with "…", the app frame never grows wider than the screen. Checked at 360, 384 and 1024 px.
- Owner / admin on the counter (phones only; staff and the counter PC unchanged):
  - ☰ in the counter header opens the shared owner/admin menu as a drawer (`app_nav` with `topbar => false` inside `.has-nav.nav-drawer`; `app-nav.css` keeps it a drawer at every width)
  - the gear shows a short "Admin" / "ॲडमिन" label
  - Lock is left out, because on an owner's phone it would end the login; Log out is in the menu
  - fixed on the way: the admin login bar style (`body:not(.has-nav) .admin-bar`) also caught the counter's drawer; it is now `body.admin:not(.has-nav)`

### 2026-11-28 – One menu for the owner page and admin (phone sidebar)

- New `app/Views/app_nav.php` + `order/assets/css/app-nav.css` + `order/assets/js/app-nav.js`, used by `admin_header.php` and the owner page:
  - phones / tablets: slim top bar with ☰; the menu slides in from the left over a dimmed page (close button, a tap outside, Escape)
  - wide screens: the same menu as the fixed side menu (unchanged look on the counter PC)
- Menu: "Live orders" (`/owner/`) first, then Dashboard and the admin pages; name, language and Log out at the bottom. It replaces the phone chip row of admin and the owner page's own top bar ("Admin" button).
- The old nav rules moved out of `admin.css` / `owner.css`. The logged-out admin login keeps its plain top bar.
- New icons `menu`, `close`; text keys `nav.open_menu`, `nav.close_menu` (mr + en).
- The owner app shows the live pages, so no APK rebuild is needed.
- Checks: 355 PHPUnit tests pass. Headless Chrome:
  - 390 px: owner page with the menu closed / open, admin reports (en / mr), login
  - 1024×768 and 1366: side menu

### 2026-11-27 – Owner app (APK)

- `android/`: Gradle project (Gradle 8.14.3 wrapper, AGP 8.13.2, compileSdk/targetSdk 36, minSdk 24) for **Mahavir Owner** / "महावीर मालक":
  - a Trusted Web Activity opening `https://<twaHost>/owner/` (default `mahaveer.foreverstory.in`; `-PtwaHost=` for production)
  - cream status/navigation bars, a splash with the logo, notifications delegated to the app (small icon = white logo mark), POST_NOTIFICATIONS for Android 13+, Custom Tab fallback without Chrome
- Icons from the client's logo via `docs/brand/build_logo.ps1` (new Android output: launcher, adaptive foreground, notification icon, splash per density).
- Signing key `%USERPROFILE%\.mahavir-android\owner-release.jks` + `keystore.properties` (outside the repo, back them up). Its SHA-256 is in `order/.well-known/assetlinks.json`, deployed with `order/`.
- Built `android/dist/mahavir-owner-staging-1.0.1.apk` (4.6 MB, git-ignored). Checked with apksigner (v2 signature, fingerprint matches the link file) and aapt2 (package, labels en/mr, launch URL, colours). The local server returns the link file as `application/json`. 355 PHPUnit tests pass.
- 1.0.0 crashed at launch: LauncherActivity enables `ManageDataLauncherActivity` ("Site settings" shortcut), which was not declared. 1.0.1 declares it (and `android:manageSpaceActivity`), as Google's template does.
- Tested 1.0.1 on a real phone (Samsung Galaxy M14, Android 15, Chrome 154) over adb: installs, no crash, opens the owner page full screen without an address bar (link file verified on staging). Notifications through the app still to test.

### 2026-11-27 – Usability review: placement fixes

- Customer ordering (phones first):
  - menu items as compact rows (name left, one pill per size right; a photo only when uploaded), about 2× more items per screen; two columns from 768 px
  - shorter intro on phones (intro sentence hidden, compact steps), so items show on the first screen
  - cart: pickup time first, with the earliest free slot already selected (server render + after the slot refresh); "Place order · ₹total" stays at the bottom while scrolling (`.checkout-submit`, sticky)
- Counter:
  - the alert bar leaves out new orders on the Online tab and table alerts on the Tables tab; the beep still repeats until any tap on that page (`acknowledge(kind)`)
  - table times over an hour read "25 h 31 min" (`tables.hours_minutes`)
  - an open order past its pickup window shows "· Late" in red (`OrderCard` `late`, server clock; `OrderCardTest`)
- Admin dashboard: today's sales, bills, open online orders and party orders to approve, above the tiles; the last turns amber when something waits.
- New text keys (mr + en): `online.late`, `tables.hours_minutes`, `admin.today.open_orders`, `admin.today.pending_bulk`.
- Checks: 355 PHPUnit tests pass. Headless Chrome:
  - 390 px: takeaway menu, cart, Marathi
  - 1366 px: takeaway
  - 1024×768: counter billing / online / tables
  - admin dashboard at 1366 (English) and 390 (Marathi)
- Not changed (spec rule): table orders still ask name + mobile once per visit.

### 2026-11-27 – Links between ordering and the website

- Ordering pages had no way back to the website. Now:
  - every customer ordering page footer has "Our website: Home · Menu · About us · Location · Contact"
  - the order-status page has "Back to the website" under "New order"
  - both appear only when `APP_URL_WEBSITE` is set
- New helper `cross_link($baseUrl, $path)` adds `?lang=` so the other side opens in the visitor's language (tested). The website's 8 order buttons use it too, so an English reader no longer lands on the Marathi ordering page.
- New text keys: `order.website_links`, `status.back_to_website` (mr + en).
- Staff screens (admin, owner page, counter) stay unlinked from the public site on purpose.
- Checks: 354 PHPUnit tests pass. Headless Chrome at 390 px (English): ordering footer and status page. Checked that the links carry `lang=en` in both directions.

### 2026-11-27 – The shop's own logo

- `docs/brand/mahavir-logo-original.jpg` (from the client) + `docs/brand/build_logo.ps1` (Windows PowerShell, System.Drawing; no new dependency):
  - removes only the white around the logo; the white "Mahavir" lettering inside the bubble stays
  - writes `logo.png` (full), `logo-compact.png` (without the tagline line), `logo-mark.png` (swirl-and-M), `favicon.png`, `icon-192/512`, `icon-512-maskable`, `badge-72` (white mark) and `og-image.png`
  - copies are in `order/assets/img` and `website/assets/img` (same names for the PWA / push icons)
- Removed: the scoop-heart SVGs and `build_logo.py` (docs/brand and both web roots).
- Website:
  - header and footer show the compact logo instead of mark + name text
  - the hero without a photo shows the full logo on white, with the best-seller card below it (not on top)
  - placeholders use the mark
- Ordering hero: compact logo. App bars (ordering, admin, owner, counter): the mark. Admin login: full logo. Favicons are PNG now.
- Colours: accent `#B4325A` → logo pink `#D81B7F` in site / shop / admin / owner CSS; pastels and category circles from the logo colours.
- Name: `shop_name_en` = Mahavir Ice Cream (seed, English titles, tests, local DB); tagline "Adding flavors to your life".
- Checks: 353 PHPUnit tests pass. Headless Chrome screenshots:
  - website home at 1366 and 390 px (Marathi + English), plus the footer
  - takeaway page at 1366 and 390 px
  - counter login, admin login and dashboard, owner page
- Open: the designer's original logo file (the 512 px app icon is upscaled from the JPG and a little soft).

### 2026-11-27 – UI redesign, phase 4: admin + owner phone

- Admin shell (`admin_header.php`, `admin.css` rewritten, boutique tokens on `body.admin`):
  - laptop / counter PC (≥1024 px): fixed white side menu with logo, icons, the logged-in name, language switch and log out
  - phones: top bar plus a sideways-scrolling row of menu chips; `admin.js` starts the row at the current page
  - new "Dashboard" menu item; menu order now follows daily use (reports, bulk orders and menu first)
- Login: centred card with the logo mark.
- Dashboard (`admin/index.php`):
  - two large tiles for the everyday screens (owner phone page, counter)
  - icon tiles with a one-line description (new `admin.tile.*` keys, Marathi + English)
- Admin pages unchanged in markup:
  - restyled cards, fields, buttons, chips, badges and tables
  - report numbers in Martel; the main report total sits on an ink card
  - grouped settings fields have their title inside the card (floated legend)
  - hidden form fields no longer leave a gap at the top of a form
  - the A4 report print layout is kept (side menu hidden, ink card printed white)
- Owner phone (`owner/index.php`, `owner.css` rewritten): light header with logo mark, dark "today's sales" card with a serif total, white order cards with a coloured edge and status pill (new = raspberry, preparing = mango, ready = green), rounded dialog. `manifest.json` / offline page colours → paper `#FBF8F3`; service worker cache renamed `mahavir-owner-v2` so installed phones refresh the cached offline page and styles.
- New icons in `icon()`: cone, home, log, logout, pulse, qr, user, users.
- Checks: 353 PHPUnit tests pass. Headless Chrome screenshots, Marathi + English:
  - 1024×768 / 1366: login, dashboard, reports, menu, item edit, settings, bulk orders, customers, website content, tables, change log
  - 390 px: login, dashboard, settings, staff, owner page with the cancel dialog
  - not re-checked: health (developer only), QR poster and table stickers (their own print styles, unchanged)
- Local only: an extra owner account `designcheck` was added to the local DB for the screenshots (to be deactivated after review; not in seed or migrations).

### 2026-11-27 – Gelato boutique look + new logo

- Mockups kept in `docs/mockups/` (gelato home page, the four logo concepts).
- Logo:
  - `docs/brand/build_logo.py` (fontTools; wordmark outlined from Cormorant Garamond) makes `logo.svg`, `logo-reverse.svg`, `logo-mark.svg`, `favicon.svg`, `icon*.svg`, `badge.svg`.
  - PNGs rendered with headless Chrome: `icon-192/512`, `icon-512-maskable`, `badge-72` (same names, so the PWA manifest and push need no change) and `og-image.png` (link previews).
- Website (`site.css` rewritten, `site_header`, `site_footer`, `index.php`):
  - info strip; header with language switch, centred logo and Order button; page links underneath
  - home: hero with facts, "menu at a glance" category circles (new `SiteService::categorySummary`, tested), best sellers, story, dark party band, visit
  - hairline menu list; light footer
- Fonts: Martel 300/700 (Marathi + Latin, self-hosted, OFL) in both web roots; the removed `cone.svg` was only used by the website.
- Ordering pages: same palette through `shop.css` tokens (ink buttons / cart bar / table and status heroes, paper background, serif headings, raspberry prices). Header and hero use the logo mark. Counter: logo mark only.
- Name: `shop_name_en` = Mahaveer Ice Cream in the seed; English titles / descriptions updated; local DB updated. The menu item "Mahavir Special" was left as is (owner can rename it in admin → Menu).
- Sample copy for the client to approve: hero "प्रत्येक स्कूपमध्ये आनंद / Joy in every scoop", "आमचे पदार्थ", "लोकांची आवडती", story line.
- Checks: 353 PHPUnit tests pass. Headless Chrome, 390 px + 1366 px, Marathi + English: website pages, takeaway with cart, status, table.
- Open:
  - founding year (seal)
  - real photos (hero, story, products)
  - About text
  - Marathi lines as shapes for print files (needs the `uharfbuzz` Python package or a designer)

### 2026-11-27 – UI redesign, phase 3: counter screens

- `counter.css` rewritten in the same look, tuned for the till, keeping every class and data hook the scripts use (no JS changes):
  - chocolate header with brand mark, staff chip (initial avatar) and Lock pill
  - white tab bar with icon pills and the red count badge inline
  - billing: pink size buttons, cart with "Current bill" heading and empty state, segmented Dine-in / Parcel, green Cash and purple UPI buttons
  - online / bulk order cards, table tiles with a status stripe, stock toggles, Today summary (total card in magenta) and rounded bills table
  - dialogs and number pads
- The new-order alert bar now sits in the frame under the tabs. It used to be fixed over the header and hid the staff name and the Lock button.
- Login: brand header, name tiles with initials, PIN pad in a card, a placeholder card until a name is tapped.
- New: icons `box`, `chart`, `globe`, `lock`, `receipt`, `settings`, `table`; lang key `billing.current_bill`. Print pages unchanged.
- Chrome 109: only allowed features (ARCHITECTURE §10). No `inset`, nesting or `color-mix()` added. Buttons stay ≥ 56 px. Checked in the installed (newer) Chrome at 1024×768 and 1366×768, Marathi + English: login, billing with cart + cash dialog, online, tables + bill dialog, bulk, stock, today. Still to check on the real counter PC: Chrome 109 itself.
- Checks: 352 PHPUnit tests pass.
- Next: phase 4 – admin + owner phone, after the developer reviews.

### 2026-11-27 – UI redesign, phase 2: public website

- Same "Warm parlour" look as the ordering pages, on all 7 website pages.
  - `site_header`: brand mark, page pills (single row with an "Order online" button on wide screens, a scrolling row on phones).
  - `site_footer`: chocolate footer with visit / contact / pages columns, plus a phone-only bottom bar with Call + Order online.
  - Home: hero with years pill, CTAs and hours, highlights strip, best-seller cards with tone placeholders, visit / party cards, CTA band.
  - Menu: `menu_list` rewritten as one card per category with letter tiles and price pills, sticky chips.
  - About, party orders (numbered step cards), photos (empty state), location (icon list, map card that links to directions until the embed code is set), contact (action cards).
- New `icon()` helper (fixed outline SVGs, decorative, tested in `HelpersTest`). New lang keys: `site.feature.*`, `site.cta.title`, `site.footer.pages`, `site.contact.whatsapp_hint` / `order_hint`. Party-order step titles lost their "1." prefixes (the number is now a badge).
- `site.css` rewritten; base.css unchanged (copies still in sync). Also fixed: the category chip rows (website and ordering pages) were cut 16 px before the screen edge.
- Checks: 352 PHPUnit tests pass. Headless Chrome at 390 px and 1366 px, in Marathi and English: no sideways scroll, no script errors.
- Owner content still missing and visible: the About text is the seed's "TODO: shop history…" line (admin → Website content), no photos, no map embed.
- Next: phase 3 – counter screens (Chrome 109), after the developer reviews; then admin + owner phone.

### 2026-11-27 – UI redesign, phase 1: customer ordering

- The developer asked for a modern redesign of all four areas, one at a time with screenshots. Chosen look: "Warm parlour" (cream background, white rounded cards, soft shadows, magenta accents). Desktop ordering: menu grid + side cart; phones stay one column.
- Done: takeaway (`index.php`), table (`t.php`), status (`order.php`), bulk (`bulk.php`).
  - Views: `header` / `footer` (brand mark, footer with icons); `shop_menu` (sticky category chips, item cards with letter tiles or photos, Add pills showing the qty in the cart); `shop_checkout` (phone sheet / desktop side panel, empty state); new `shop_cart_bar`.
  - `shop.css` rewritten (tokens scoped to `.shop-body`; base.css unchanged).
  - `shop.js`: wide-screen layout via `matchMedia`, sheet with ×/Escape, qty badges, category chips highlight the section in view.
  - New lang keys: `order.open_until`, `order.step_*`, `order.cart_empty_*`, `order.close`, `bulk.when_who`.
  - No business logic, API or schema changes; every `data-*` hook kept.
- Checks: 351 PHPUnit tests pass. Headless Chrome at 390 px and 1366 px, in Marathi and English: no sideways scroll, no script errors. A takeaway order was placed through the new sheet (local order 39) and its status page checked.
- Next: phase 2 – public website, after the developer reviews phase 1 screenshots; then counter screens (Chrome 109), then admin + owner phone.

### 2026-11-27 – Staging setup without shell access

- Staging account (CloudLinux cPanel; account details kept out of this public repo): subdomain `mahaveer.foreverstory.in` → `~/mahavir/order`, DNS and Let's Encrypt certificate in place (valid to 2027-02-24), staging database created. The account hosts other sites, so the website copy goes to `~/mahavir_site`, never `public_html`. The host has shell access disabled (no Terminal, SSH refused).
- New: `database/prepare_server.php` (run on the PC) + `App\ServerPackage`: builds the accounts in a temporary local database with the usual checks and writes `storage/deploy/<name>/config.php` (own push keys) and `database.sql` (migration files + seed + applied-migrations rows + accounts as hashes) for phpMyAdmin. Prompts moved to `database/cli_prompts.php`, shared with `create_owner.php`. `Migrator::files()` / `MIGRATIONS_TABLE_SQL`, `BackupService::dumpRows()`. DEPLOYMENT §3b.
- Checks: `ServerPackageTest` (SQL file into an empty database → full menu, migrations recorded, logins work incl. a password with quotes / backslash; config.php values exact); a scripted run of the script, its SQL imported into a scratch database with the mysql client (20 tables, 3 accounts, no plain password in the file). Fixed `CronAndCleanupTest`, which mixed a fixed date with real file times and failed once the date changed. 348 tests pass.
- Next: run `prepare_server.php` for staging, import, deploy via cPanel Git, upload config, cron jobs, then GO_LIVE.md §2.

### 2026-11-26 – M9 Hardening & go-live (code part)

- Security review line by line → `docs/SECURITY_REVIEW.md` (all 11 items pass; token scans of every SQL call with a variable and every unescaped output, CSRF on all 25 POST handlers, auth guard per endpoint, randomness, uploads). Added headers (Permissions-Policy, HSTS in production, no X-Powered-By); TEST stamp on non-production QR sheets.
- Crons: `cron/backup.php` (BackupService), `cron/cleanup.php` (CleanupService), `cron/auto_noshow.php` now through `App\Cron` (lock, last run, exit code). Migration 002 (two indexes, applied locally). admin → System health (`HealthService`, super-admin only). `.cpanel.yml` final; `config.example.php` notes the optional `MYSQLDUMP_PATH`.
- Docs: `docs/GO_LIVE.md` (staging → full test of every milestone on real devices → production → printing → shop → first evening → rollback / restore), DEPLOYMENT release checklist + restore steps, ARCHITECTURE §13 (backups, cleanup, crons, health, headers).
- Checks: 346 PHPUnit tests pass (new: `BackupTest` – PHP dump restored into the test DB, every table identical incl. quotes / backslashes / `;` / line breaks / Marathi / NULLs; `CronAndCleanupTest`; `DeployConfigTest`). Ran the three crons locally: backups by PHP dump and by XAMPP's mysqldump both restored into a scratch database with all 20 tables identical (CHECKSUM). HTTP: headers on order pages, an API and website pages; System health 403 for the owner, 200 with 14 checks for the super-admin, nav link only for super; TEST stamp on the poster and all 7 stickers (checked in a printed PDF too); `/cron/` not reachable from the web. Page crawl complete.
- Dev data: a `dev` super-admin login was added to the local database only, to check the health page.
- Not doable here: staging / production deploys, Lighthouse, the phone and printer checks, the shop setup and the real evening – all in GO_LIVE.md.
- Next: the go-live itself. After the first evening without errors, tick M9.

### 2026-11-26 – M8 Public website

- Website (`website/`): home (hero, best sellers, order buttons), menu (live), about, bulk, gallery, location (tap-to-load map), contact (call, WhatsApp when set); shared `site_header` / `site_footer` views (nav, banner, SEO tags, JSON-LD), `site.css`, `site.js` (map only), `media.php`, `sitemap.xml`, `robots.txt`, placeholder art (`cone.svg`) and icons. `SiteService` (text, banner, gallery, best sellers, structured data, directions / WhatsApp links); `SettingsService` map-embed field; `ImageService::dimensions()`; bootstrap skips sessions for the website.
- Admin: `admin/website.php` (banner, About, gallery), nav + dashboard tile, audit labels; Settings has the "Embed a map" field.
- Checks: 336 PHPUnit tests pass (new: `SiteServiceTest`, `SiteFilesTest`). Scripted HTTP run (order app :8000, website :8003):
  - all 7 pages complete, with their own title / description, canonical, OG, `noindex` off production, CSP and no cookie; sitemap and robots
  - JSON-LD; automatic best sellers
  - **a price changed in the admin item form shows on the website menu at once**; ₹0 / inactive hidden; sold-out badge
  - banner on → all 7 pages, off → gone; About paragraphs, HTML shown as text
  - gallery upload through the admin form → gallery (1200×900 size attributes) + home hero; `media.php` cacheable, no cookie, refuses other paths
  - map code pasted as Google's whole iframe → URL kept, foreign address refused, CSP frame-src only Google; JSON-LD geo
  - WhatsApp hidden until set; `?lang=en`
- Headless Chrome: every page at 360 px without sideways scroll, one h1, all images with alt + size, no tiny tap targets; the map makes no Google request before the tap and loads Google's frame after it; no script errors.
- Speed: Lighthouse itself could not be fetched here (npx download not allowed). A Lighthouse-like run in the installed Chrome (slow 4G, CPU ×4, cold cache) gave LCP 1.0–1.8 s, layout shift 0 and about 150 KB per page. The official Lighthouse scores are DEPLOYMENT §8.
- Fixed while checking: a setting without a database row counted as "changed" on every save (spurious audit entries). Best-seller placeholders without item photos were large empty boxes on phones and are now a short strip.
- Test data removed afterwards (test photo deleted through admin, made-up map code cleared).
- Next: M9 (Hardening & go-live) after the developer confirms M8.

### 2026-11-26 – M7 Reports & customers

- Reports: `ReportService` range reports (summary, payment split, sales by day incl. empty days, order types, item-wise, top flavours, staff-wise, cancelled bills; `range()` for presets / custom dates) with `daySummary()` built on them; `ReportExport` (CSV rows per report); `App\Csv`. Page `admin/reports.php`: presets + custom range, summary cards, one section per report with its own CSV link, Print (A4 print styles in `admin.css`: menus / forms / buttons hidden, column names repeat per page, totals printed once).
- Customers: `admin/customers.php` (search, filter all / blocked / with no-shows, 50 per page, block / unblock with confirm, detail with the latest 20 orders, CSV download), `CustomerService` search / find / orders / block / export. `admin/blocked.php` now redirects (301) to the Blocked filter. Admin nav + dashboard: Reports and Customers; audit page labels the two new actions.
- Checks: 323 PHPUnit tests pass (new: `ReportRangeTest` with a 3-day fixture through the real services, `CsvTest`, `CustomerAdminTest`). Scripted HTTP run: staff refused; today's report = counter Today tab = printed day close; all 7 CSVs (BOM, `text/csv`, file names, Marathi names, column counts, month CSV totals agree with each other and with today); presets, bad / too-long / junk ranges; customers search (+91 number, part of a name, `%` literal), block → blocked filter → unblock (foreign "back" ignored), no-CSRF refused, audit entries, detail rows = DB, customers CSV = all rows + audit entry, staff cannot export, old URL redirects. Headless Chrome: no sideways scroll at 360 px on reports / customers / customer detail, print view hides menus and shows the print header, `printToPDF` gives A4 pages, no script errors. Crawl: all pages complete.
- Fixed while checking: on paper, whole report sections refused to split (large gaps), and Chrome repeated each table's total row at the foot of every page (a "total" under rows that did not add up to it). Long tables now split, headings stay with their table, totals print once.
- Not verified here: opening the CSVs in Excel (bytes checked: UTF-8 with BOM, CRLF, quoting).
- Next: M8 (Public website) after the developer confirms M7.

### 2026-11-26 – M6 Owner's phone (PWA + Web Push)

- Push: `PushService` (subscribe / unsubscribe / mute / status, `notifyOwners`, `notifyDevice` for the test button, message builders `forOrder` / `forBillRequest` / `forTest` in both languages; never throws), `database/vapid_keys.php`, helpers `after_response()` / `notify_owners_later()`. Wired into `place_order`, `place_table_order`, `bulk_request` and `table_alert` (Request bill; `TableService::raiseAlert` now returns whether a new alert was added).
- Owner page `order/owner/` (`index.php`, `manifest.json`, `offline.php`), `order/sw.js`, `assets/js/owner.js`, `assets/css/owner.css`, placeholder icons. APIs: `owner_action.php`, `push_subscribe.php`, `poll.php?view=owner`; `OrderCard::bulk`. Admin dashboard tile "Owner phone page"; admin login supports `?next=/owner/`; `Auth::requireAdmin($loginUrl)`.
- Counter: print queue (one print at a time) + `Counter.printKot()` for `kot_pending` from the poll; Online and Tables tabs use it on Accept.
- Checks: 301 PHPUnit tests pass (new: `PushServiceTest`, `OwnerPhoneTest`). Scripted HTTP run against a stand-in push service on 127.0.0.1:8002, with the "phone" decrypting each push (RFC 8291) using its own keys: login + `next`, staff refused (401), manifest / icons / sw.js / offline page, subscribe (bad / no-CSRF refused), test push decrypts to "चाचणी सूचना", takeaway / table / bulk / Request bill pushes with the right Marathi + English text and link, mute stops pushes and unmute restarts, owner Accept → counter `kot_pending`, owner cancels an accepted order without PIN, bulk approve with ₹500 advance (too high refused) and cancel, 410 phone removed while an unreachable one is kept and the order still saves. Headless Chrome (DevTools protocol): login → owner page, cards render, Accept, Cancel and Approve dialogs (bad input refused), service worker active, a delivered push shows a Marathi notification and an English one after switching the page language, a foreign link in a push opens `/owner/`, "No internet" page when the server is down and back to the page after, no layout overflow at 360 px, no script errors. Page crawl: 39 pages complete.
- Found and fixed while checking: in a Chrome profile whose Cache Storage fails, the service worker never installed and the notification panel stayed on "Loading…" → the offline-page cache is now optional and the page gives up waiting for the worker after 15 s.
- Not verified here (needs an Android phone + HTTPS on staging): notification with the screen locked, tapping it, "Add to Home screen". Checklist in DEPLOYMENT §7. Before staging: generate staging VAPID keys there (`php database/vapid_keys.php`), never copy the local ones.
- Next: M7 (Reports & customers) after the developer confirms M6.

### 2026-11-26 – M5 Bulk orders

- Services: `BulkService` (request, approve, cancel/reject, updateLines, recordPayment, markReady, complete, open, recent, balance, advanceDue); `CartService` channel `bulk` with decimal quantities (`parseQty`); `CustomerService::validateContact` shared by takeaway / table / bulk; `OrderService::markReady` and customer cancel cover bulk; `BillFormatter` bulk extras; helper `customer_status()`.
- Customer: `order/bulk.php` + `bulk_form.js` (pick item + quantity, estimate, event date, pickup time, contact, note), link from the takeaway page; status page handles bulk (event date, advance due, paid / balance, rejection reason). API: `bulk_request`, `customer_cancel` (bulk pending).
- Owner: `admin/bulk.php` (pending / approved / recent lists; detail with editable lines, approve with advance, reject / cancel with reason, payments); dashboard tile shows pending count. Counter: Bulk tab (`counter/bulk.php`, `bulk_counter.js`, API `bulk_action`).
- **Bug fixed from M1:** `admin/items.php` stopped rendering at the variants table for any existing item (numeric array keys became ints → `str_starts_with()` TypeError under strict types), so prices could not be edited in a browser. The M1 scripted check only looked at text above the table. A page crawl (every admin / counter / customer / print page must return 200 and end with `</html>`, no new log errors) is now part of the checks.
- Checks: 285 PHPUnit tests pass. Scripted HTTP run (PHP script, so Marathi text is sent intact): owner adds "1 किलो" Mawa ₹400 in admin → customer requests 3 kg Mawa + 2× Shrikhand 500 g (today refused, day after tomorrow accepted, status "मंजुरी बाकी", ₹1,600) → owner approves with ₹500 advance (customer sees "आगाऊ ₹500 …") → Bulk tab records ₹500 cash (overpay refused), Ready, Picked up by UPI → bill with "आगाऊ भरले ₹500 / आत्ता भरले ₹1,100" → customer sees "घेऊन गेले"; rejection reason shown to the customer; self-cancel while pending; 4th request in an hour refused; day summary cash ₹500 + UPI ₹1,100. Crawl: 36 pages complete, website home renders, no new errors.
- Not verified here: the pages in a real phone / Chrome 109 browser and printing. The local dev DB holds test data from the scripted runs.
- Next: M6 (owner's phone: PWA + Web Push) after the developer confirms M5. Before M6: enable `gmp` (and `intl`, `sodium`) in `C:\xampp\php\php.ini`; Web Push needs HTTPS, so test it on staging.

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
