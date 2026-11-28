# Build Plan

Build in this order. Each milestone ends with: tests pass, manual checklist done, `docs/PROGRESS.md` updated, commit. Counter billing comes early so the shop can pilot it while online ordering is built.

---

## M0 – Project foundation

**Build**
- Folder structure from `ARCHITECTURE.md` §1, `.gitignore` (config.php, storage/*, tools/*.phar, .env files, OS junk).
- `composer.json` with `minishlink/web-push` and `chillerlan/php-qrcode`; run `composer install --no-dev --optimize-autoloader`; commit `vendor/`.
- `app/bootstrap.php`, `config.example.php`, `helpers.php`, `Db.php`, `Lang.php`, `Csrf.php`, `Settings.php`, autoloader, error handling, logging.
- `database/migrate.php` (tracks applied files in a `schema_migrations` table; flags `--seed`, `--fresh`; refuses `--fresh` when `APP_ENV=production`).
- Run `001_schema.sql` and `seed.sql`.
- `lang/mr.php`, `lang/en.php` with initial keys; language toggle helper.
- Base CSS: tokens from SPEC §16, reset, typography (self-hosted Noto Sans Devanagari woff2), buttons, forms, cards, grid utilities. Separate `counter.css` for the touch layout.
- `phpunit.xml`, `tests/bootstrap.php` (builds `mahavir_test`), first tests for `PricingService` and money helpers.
- Placeholder `order/index.php` and `website/index.php` that show the menu from the DB.

**Done when**
- `php database/migrate.php --fresh` creates all tables and seed data with no errors.
- `php -S localhost:8000 -t order` shows the seeded menu in Marathi, toggles to English.
- `php tools/phpunit.phar` passes.

---

## M1 – Login, roles, admin menu & settings

**Build**
- `Auth`: staff login on the counter by tapping a name then entering the PIN; owner/super-admin username + password (admin); per-account lockout after 5 failures for 15 min (owner can unlock in admin); role checks; logout/lock (no auto-lock).
- First-run script `database/create_owner.php` (CLI) to create the owner account and staff PINs.
- Admin: categories, items, variants (CRUD, sort order, active, online enabled, photo upload → WebP), ₹0 activation guard.
- Admin: settings page for every key in SPEC §14.
- Admin: staff management.
- `AuditService` logging for price/settings/staff changes; audit log page.

**Done when**
- Staff PIN works on the counter login; owner login works on admin; wrong PIN 5× locks.
- Owner can change Butterscotch Full from ₹50 to ₹55 and it appears on the menu; audit log shows it.
- Activating "Kulfi" with ₹0 price is refused with a clear message.

---

## M2 – Counter billing (pilot-ready)

**Build**
- Counter layout (SPEC §9): tabs, name + PIN lock, "Tap to start" audio unlock, "No internet" banner.
- Billing tab: category → item → variant, cart edit (+/−, remove), Dine-in/Parcel, Cash/UPI/Mixed, cash tendered + change, Save & Print.
- `SequenceService` (token + bill numbers), `OrderService::createCounterOrder`, `PaymentService`.
- Print templates: bill and day summary (SPEC §10), hidden-iframe printing.
- Stock tab (toggle availability, audit logged).
- Today tab: list, reprint (marked "DUPLICATE / प्रत", audit logged), cancel with owner PIN + reason, day close summary + print.

**Tests**: sequences (daily reset, no duplicates), counter order totals with/without parcel, cancel rules.

**Done when** (test on a 1024×768 window in Chrome)
- A bill of 2× Badam Shake Full + 1× Vanilla Half, Parcel (₹160 + ₹15 + ₹5 parcel = ₹180), Cash ₹200 shows change ₹20 and prints a correct 80 mm bill with token.
- Token resets next day (test by changing the date in a test); bill numbers continue.
- Cancelled bill stays listed as Cancelled; day summary excludes it from totals.
- Every page in `order/counter/` uses only features allowed for Chrome 109.

> Pilot: deploy M0–M2 to staging, then production, and let the shop bill on it for 1–2 weeks while M3+ are built.

---

## M3 – Takeaway ordering + online orders panel

**Build**
- Customer menu (`order/index.php`): categories, variants, sold-out state, cart in `sessionStorage`, sticky cart bar.
- Checkout: name, mobile, slot picker (`SlotService`), note; "Pay at counter on pickup".
- `CartService` server validation, `CustomerService` upsert, blocked-number check, rate limits.
- Order status page with `order_key`, 10 s polling.
- "My recent orders" + reorder.
- Closed-hours banner.
- Counter Online orders tab: polling `poll.php`, sound alert loop until acknowledged, Accept / Ready / Picked up (collect payment → bill number → print bill) / Cancel.
- `cron/auto_noshow.php` (every 5 min): mark `no_show`, increment customer no-shows, block at threshold.
- Takeaway QR poster (A4) in admin.

**Tests**: slots (earliest, last, closed, capacity), cart validation, status transitions, no-show/blocking.

**Done when**
- Order placed on a phone appears on the counter within 5 s with a beep; Accept → status page shows Preparing; Ready → shows Ready; Picked up → payment + bill printed.
- 11th order in the same slot is refused ("slot full"), and the slot shows as full.
- Ordering at 10:05 PM shows the closed banner.
- A number with 2 no-shows gets the blocked message.

---

## M4 – Table ordering

**Build**
- Admin tables: T1–T7, regenerate token, A4 sticker sheet with QR (`QrService`), table number and "Scan to order".
- `t.php`: table menu, name/mobile once per session, place order, order list with statuses, Call staff, Request bill.
- `TableService`: open session on first order, running bill, move order, settle (one bill number on the session, payments, print combined bill, close).
- KOT print on Accept.
- Counter Tables tab: tiles with status, total, minutes, alerts, unsettled warning; table detail screen.
- Online orders tab shows table orders with "TABLE 4" in very large text.

**Tests**: session lifecycle, running totals across multiple orders, move order, settlement numbering, no parcel charge on table orders.

**Done when**
- Two orders from T4 appear as one running bill; settling prints one combined bill and turns T4 Free.
- Regenerated T4 token makes the old QR show the friendly error.
- Call staff on T7 beeps on the counter and shows on the T7 (Outside) tile.

---

## M5 – Bulk orders

**Build**
- `bulk.php` form (items with decimal quantity, event date ≥ tomorrow, pickup time, contact, note).
- Admin bulk list: approve (edit lines, set advance) / reject with reason; record advance and balance payments; mark ready/completed; print bill on completion.

**Done when**
- A request for 3 kg Mawa + 2× Shrikhand 500 g for the day after tomorrow appears as Pending approval; owner sets ₹500 advance; advance payment recorded; balance shown correctly at pickup.
- Event date of today is refused.

---

## M6 – Owner's phone (PWA + Web Push)

**Build**
- `order/owner/`: login, live orders list, accept/cancel/approve bulk, today's sales total, mute switch.
- `manifest.json`, icons (placeholder until logo), `sw.js` (push handler, notification click opens the order).
- `PushService` with VAPID; subscribe/unsubscribe; send after commit; remove dead subscriptions.

**Done when**
- On an Android phone with the screen locked, a new table order shows a notification within a few seconds; tapping opens the order.
- Muting stops notifications; unmuting restarts them.

---

## M7 – Reports & customers

**Build**
- Reports with date range: sales by day, item-wise, top flavours, payment split, order-type split, staff-wise, cancelled bills; CSV export; printable view.
- Customers list: search by mobile/name, order count, last order, no-shows, block/unblock.

**Done when**
- Report totals for a test day equal the day-close summary for that day.
- CSV opens correctly in Excel with Marathi names (write UTF-8 with BOM).

---

## M8 – Public website

**Build**
- Pages from SPEC §13, shared header/footer, language toggle, announcement banner.
- Menu page from DB (hide ₹0/inactive, show sold-out).
- Admin content editor: About (mr/en), banner, gallery uploads.
- SEO: titles, descriptions, LocalBusiness JSON-LD, Open Graph, sitemap.xml, robots.txt.
- Placeholders for logo, photos, story, map link (clearly marked `TODO: owner content`).

**Done when**
- Lighthouse on mobile: Performance ≥ 90, Accessibility ≥ 90, SEO ≥ 95.
- Price change in admin appears on the website menu immediately.

---

## M9 – Hardening & go-live

**Build / check**
- Security checklist (ARCHITECTURE §11) reviewed line by line; fix gaps.
- `cron/backup.php` and `cron/cleanup.php` (old logs, rate-limit rows).
- `.cpanel.yml` final; deploy to staging, full manual test, then production (DEPLOYMENT.md).
- Counter PC setup checklist (DEPLOYMENT.md §6) done at the shop.
- Print table QR stickers and takeaway poster from production (never from staging).

**Done when**
- One full evening of real orders (counter + takeaway + tables) runs without errors in the logs.

---

## Phase 2 (do not build now)

Razorpay (or any payment gateway) online payments – pay-first takeaway by manual UPI is in V1 (decision 2026-11-28), WhatsApp notifications for takeaway orders, SMS, OTP, delivery, loyalty/coupons, GST, Play Store app, token display screen, inventory, aggregator integration.
