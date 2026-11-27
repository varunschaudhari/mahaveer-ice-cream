# Go-live runbook (M9)

Step by step from staging to the first real evening. Tick each box; anything that fails stops the go-live until it is fixed. Server setup details are in `DEPLOYMENT.md`, the security review in `SECURITY_REVIEW.md`.

**Done when (BUILD_PLAN M9):** one full evening of real orders (counter + takeaway + tables) runs without errors in the logs.

---

## 1. Staging (`mahaveer.foreverstory.in`)

- [ ] DEPLOYMENT §3 done on the staging account (subdomain, SSL, PHP, database, `mahavir_deploy.env`, Git Version Control on the `staging` branch).
- [ ] Deploy (Git Version Control → Update from Remote → Deploy HEAD Commit).
- [ ] `$APPDIR/app/config.php`: `APP_ENV = staging`, staging URLs (`https://…`), staging database. Over SSH run `php database/vapid_keys.php` and paste its 3 lines (staging gets its own keys).
- [ ] Over SSH: `php database/migrate.php --seed`, then `php database/create_owner.php` (owner login, staff PINs, and say yes to the optional super-admin account: that is your developer login for System health).
- [ ] Cron jobs (DEPLOYMENT §3 step 9): `auto_noshow` every 5 min, `backup` nightly, `cleanup` weekly. Run each once by hand over SSH: `php $HOME/mahavir/cron/backup.php` etc.
- [ ] admin → **System health**: everything **OK** except `APP_ENV` (staging is expected to say "Check").
- [ ] Download the backup from `storage/backups/` (File Manager) and restore it once into an empty database in phpMyAdmin (Import). The tables and row counts must match. This proves backups work *before* they are needed.

## 2. Full test on staging (one person at the counter PC, one or two phones)

Use real devices: the counter PC (Chrome 109, TM-T82 printer), an Android phone for the owner page, another phone as a customer.

**Counter (M2)**
- [ ] Log in with name + PIN; bill 2× Badam Shake Full + 1× Vanilla Half, Parcel, cash ₹200 → total ₹180, change ₹20; the bill prints with Marathi names and the token on top.
- [ ] UPI bill, Cash + UPI bill; discount with owner PIN; cancel a bill on the Today tab with owner PIN; reprint shows "DUPLICATE / प्रत".
- [ ] Sold-out toggle on the Stock tab; the item shows sold out on the customer menu.
- [ ] Switch off the shop router: the red "No internet" banner shows; hotspot on → back to normal.

**Takeaway (M3)**
- [ ] Customer phone: scan the takeaway poster (staging), order with a pickup slot → the counter beeps and shows the order; Accept → Ready → Picked up (payment) → bill prints; the customer page follows each step.
- [ ] Customer cancels a new order from their page; a second order is refused after 5 in 10 minutes from one phone.

**Tables (M4)**
- [ ] Scan a table sticker (staging), order; second phone at the same table joins the same bill. Counter Tables tab: Accept → KOT prints; Served; Call staff / Request bill alerts; Move an order; Settle (one bill, table free).

**Bulk (M5)**
- [ ] Customer bulk request for the day after tomorrow; owner approves with an advance in admin; counter Bulk tab records the advance, Ready, Picked up with the balance; bill shows advance + balance.

**Owner's phone (M6) – the M6 "Done when"**
- [ ] `/owner/` on the Android phone, Add to Home screen, Turn on notifications, Send a test.
- [ ] **Screen locked**, place a table order from the customer phone → the notification arrives within a few seconds; tapping opens the order. Accept on the phone → the KOT prints at the counter.
- [ ] Mute → no notification; unmute → notifications again.

**Reports & customers (M7)**
- [ ] admin → Reports → Today: "Money received" equals the counter's Today tab and the printed day close.
- [ ] Download the items CSV and the customers CSV; open them in **Excel**: Marathi names readable, amounts add up.
- [ ] Print the report (A4 or Save as PDF) from the phone or another PC, not from the counter shortcut.

**Website (M8)**
- [ ] Every page on the phone; change a price in admin → the website menu shows it at once.
- [ ] Lighthouse (Chrome DevTools → Lighthouse → Mobile, Incognito) on the home and menu pages: Performance ≥ 90, Accessibility ≥ 90, SEO ≥ 95. Note the scores in PROGRESS.md.

**Health**
- [ ] After the test: admin → System health shows no errors in today's log (warnings for the deliberately wrong PINs are fine).

## 3. Production

- [ ] Merge to `main`; production account set up as DEPLOYMENT §3 (both domains, AutoSSL, **Force HTTPS**, PHP-FPM if offered).
- [ ] cPanel → **Indexes** → "No Indexing" for `public_html` and the order subdomain. MultiPHP INI Editor: `expose_php = Off`, `display_errors = Off`, `upload_max_filesize = 8M`, `post_max_size = 10M`.
- [ ] Deploy; `config.php` with `APP_ENV = production`, `https://order.mahaveericecream.in` and `https://mahaveericecream.in`, the production database, **new** VAPID keys from `php database/vapid_keys.php` on this server, `VAPID_SUBJECT = https://mahaveericecream.in`.
- [ ] `php database/migrate.php --seed`, then `php database/create_owner.php` (owner, staff PINs, your super-admin account).
- [ ] Owner content: prices for the ₹0 Kulfi items (or leave them inactive), About text, gallery photos, Google Maps link + "Embed a map" code, WhatsApp number if wanted, bill footer.
- [ ] Cron jobs; run each once by hand; System health all **OK**.
- [ ] Test order on production (counter bill + cancel it on the Today tab), so the bill number sequence starts clean the first evening if you prefer: note the first real bill number.
- [ ] Google Search Console: add `mahaveericecream.in`, submit `https://mahaveericecream.in/sitemap.xml`.
- [ ] Owner app: rebuild the APK for production (`./gradlew.bat assembleRelease -PtwaHost=order.mahaveericecream.in`, `versionCode` raised – DEPLOYMENT §7b), install it over the staging app on the owner's phone, check `https://order.mahaveericecream.in/.well-known/assetlinks.json` opens, turn notifications on again and send a test.

## 4. Print the QR stickers and poster (production only)

- [ ] admin → Tables → **Regenerate QR** for every table (the seeded codes are not random enough – SECURITY_REVIEW.md item 5). Old staging stickers stop working, as intended.
- [ ] admin → Tables → Print stickers, and admin → Takeaway QR poster. On production there is **no** "TEST – DO NOT USE" stamp across the QR codes; if you see one, you are not on production.
- [ ] Scan every sticker with a phone before laminating: each opens its own table.

## 5. At the shop

- [ ] Counter PC checklist (DEPLOYMENT §6) and owner's phone (DEPLOYMENT §7).
- [ ] Short training: staff log in with their PIN, Lock button, Online tab beep, Tables tab, Bulk tab, Today tab. The owner: approvals with PIN, owner page, admin.

## 6. The first real evening

Before opening
- [ ] System health: crons OK, backup from last night present, no errors today.
- [ ] Counter logged in, sound on (tap once), printer has paper.

During
- [ ] Watch for anything odd on the counter; note the time and what happened.

After closing
- [ ] Day close (Today tab → Print summary) matches the cash drawer and the UPI app.
- [ ] System health → **Today's log: no errors**. Warnings such as a mistyped PIN are fine; any **error** line is investigated before the next evening.
- [ ] Next morning: the backup cron ran (System health), and `auto_noshow` marked uncollected orders.

When the evening ran without errors, M9 is done: record it in PROGRESS.md.

## If something goes wrong

- **A bad deploy:** cPanel → Git Version Control → deploy the previous commit. Migrations only ever add things, so older code keeps working with the newer database.
- **Restore the database** (last resort, loses everything after the backup): in phpMyAdmin select the database → Import → the `.sql.gz` from `storage/backups/`; or over SSH `gunzip -c storage/backups/mahavir-YYYY-MM-DD-HHMM.sql.gz | mysql -u USER -p DBNAME`. Then check System health.
- **Internet down at the shop:** mobile hotspot (DEPLOYMENT §6); paper bills until it is back, enter them later as counter bills.
