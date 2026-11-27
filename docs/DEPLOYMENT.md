# Setup & Deployment

## 1. Local development (Windows 11)

Install once:

1. **XAMPP** (free) – used for MySQL/MariaDB (start "MySQL" in the XAMPP control panel). You can also use XAMPP's bundled PHP.
2. **PHP** – use the same major version as the cPanel server (check cPanel → MultiPHP Manager). If XAMPP's PHP is older, download PHP for Windows (Thread Safe x64 zip) from windows.php.net, unzip to `C:\php`, add it to PATH. Minimum PHP 8.2.
3. In `php.ini` enable: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `gd`, `fileinfo`, `intl`, `gmp`, `sodium`.
4. **Composer** for Windows (getcomposer.org) – only used locally to refresh `vendor/`.
5. **Git** for Windows, and a GitHub account.
6. **PHPUnit phar** – download `phpunit-11.phar` (works with PHP 8.2+) from phar.phpunit.de and save it as `tools/phpunit.phar` (gitignored).
7. **Claude Code** – install per the official docs and run it from the repo root.

Create databases in phpMyAdmin (http://localhost/phpmyadmin): `mahavir` and `mahavir_test`, collation `utf8mb4_unicode_ci`.

```bash
git clone git@github.com:<you>/mahavir-icecream.git
cd mahavir-icecream
copy app\config.example.php app\config.php      # then edit DB credentials, APP_ENV=local
php database/migrate.php --fresh
php database/create_owner.php                    # owner login + staff PINs (from M1)
php -S localhost:8000 -t order                   # ordering app
php -S localhost:8001 -t website                 # website (second terminal; any free port works, APP_URL_WEBSITE is only used for canonical / OG links)
php tools/phpunit.phar
```

Test customer pages from your phone on the same Wi-Fi: run `php -S 0.0.0.0:8000 -t order` and open `http://<your-PC-IP>:8000`. (Web Push needs HTTPS, so test push on a phone on staging.)

**Web Push locally (M6):** create local VAPID keys once and paste the three lines into `app/config.php`. On Windows, PHP's OpenSSL can only create these keys when `OPENSSL_CONF` is set *before* PHP starts, both for the key script and for the server that sends pushes:

```bash
set OPENSSL_CONF=C:\xampp\php\extras\ssl\openssl.cnf     # cmd; Git Bash: export OPENSSL_CONF='C:\xampp\php\extras\ssl\openssl.cnf'
php database/vapid_keys.php
php -S localhost:8000 -t order                              # same terminal, so sending works too
```

Desktop Chrome treats `http://localhost:8000` as secure, so `/owner/` → "Turn on notifications" works there for a local check. Linux servers (cPanel) need no `OPENSSL_CONF`.

## 2. GitHub

- Private repository, e.g. `mahavir-icecream`.
- Branches: `main` = production, `staging` = staging. Work on feature branches, merge to `staging`, test on staging, then merge `staging` → `main`.
- Never commit `app/config.php`, `storage/`, `tools/*.phar`, or database dumps.

## 3. cPanel one-time setup (repeat on the staging account)

1. **Domains:** `mahaveericecream.in` (website). Create subdomain `order.mahaveericecream.in` with document root `/home/<user>/mahavir/order`.
   Staging account: subdomain `mahaveer.foreverstory.in` with document root `/home/<user>/mahavir/order`; optional `mahaveer-site.foreverstory.in` for the staging website.
2. **SSL:** cPanel → SSL/TLS Status → run AutoSSL for all domains. Force HTTPS redirect (cPanel → Domains → Force HTTPS Redirect).
3. **PHP:** MultiPHP Manager → latest PHP for all domains. Extensions (Select PHP Version, if available): pdo_mysql, mbstring, openssl, curl, gd (with WebP), fileinfo, exif, intl, gmp, sodium. MultiPHP INI Editor: `upload_max_filesize = 8M` and `post_max_size = 10M` (phone photos are 3–5 MB; the host default is often 2M). Sessions are stored in `storage/sessions` (created automatically), not the shared `/tmp`.
4. **Database:** MySQL Databases → create DB + user, grant ALL on that DB. Staging uses its own DB.
5. **Deploy settings file** (one per server) – create `/home/<user>/mahavir_deploy.env` with File Manager:

   ```sh
   APPDIR=/home/<user>/mahavir
   WEBDIR=/home/<user>/public_html
   ```

   (Staging: `WEBDIR` = document root of `mahaveer-site.foreverstory.in`, or a dummy folder if you skip the staging website.)
6. **Config:** after the first deploy, create `/home/<user>/mahavir/app/config.php` from `config.example.php` with production values (`APP_ENV=production`, DB credentials, VAPID keys, URLs). The deploy never overwrites it.
   - VAPID keys: over SSH run `php /home/<user>/mahavir/database/vapid_keys.php` **once** and paste the output; staging gets its own pair. Set `VAPID_SUBJECT` to `https://mahaveericecream.in` (a contact for the push services). Never regenerate production's keys: every subscribed phone would stop getting notifications until its owner page is opened again.
   - Pushes are sent after the customer's reply. With PHP-FPM (or LiteSpeed's LSAPI) the reply goes out first; with plain CGI the order page waits up to ~10 s if the push service is slow. If MultiPHP Manager offers PHP-FPM for `order.mahaveericecream.in`, turn it on.
7. **Git Version Control:**
   - cPanel → SSH Access → Manage SSH Keys → generate a key; add its public key to GitHub repo → Settings → Deploy keys (read-only).
   - cPanel → Git Version Control → Create → Clone URL `git@github.com:<you>/mahavir-icecream.git`, repository path `/home/<user>/repositories/mahavir-icecream`. Production checks out `main`; staging checks out `staging`.
8. **First database load:** phpMyAdmin → import `database/migrations/001_schema.sql`, then `database/seed.sql` (or run `php database/migrate.php --seed` over SSH). Then run `php /home/<user>/mahavir/database/create_owner.php` over SSH.
9. **Cron jobs** (cPanel → Cron Jobs). Find the PHP binary path in MultiPHP or use `/usr/local/bin/php`:

   ```
   */5 * * * *  /usr/local/bin/php /home/<user>/mahavir/cron/auto_noshow.php >/dev/null 2>&1
   30 23 * * *  /usr/local/bin/php /home/<user>/mahavir/cron/backup.php      >/dev/null 2>&1
   0 4 * * 0    /usr/local/bin/php /home/<user>/mahavir/cron/cleanup.php     >/dev/null 2>&1
   ```

### 3b. Servers without shell access (no Terminal, SSH refused)

Everything that needs the command line is prepared on your PC instead:

1. **On your PC** (XAMPP MySQL running), in Git Bash from the repo root:
   ```bash
   OPENSSL_CONF='C:\xampp\php\extras\ssl\openssl.cnf' /c/xampp/php/php.exe database/prepare_server.php
   ```
   It asks for the server's URLs and database login, makes that server's own push keys, and asks for the owner / super-admin / staff accounts (same checks as `create_owner.php`). Result in `storage/deploy/<name>/` (gitignored): `config.php` and `database.sql`.
2. **Database:** cPanel → phpMyAdmin → select the server's **empty** database → Import → `database.sql`. It holds the migration files, `seed.sql`, the applied-migrations rows and the accounts (hashes only).
3. **Code:** cPanel → Git Version Control as in §3.7. Without SSH a private repo cannot use a deploy key: clone with `https://<token>@github.com/<you>/mahaveer-ice-cream.git`, where `<token>` is a GitHub **fine-grained token** with read-only *Contents* access to this repository only. Then Deploy HEAD Commit.
4. **Config:** File Manager → `$APPDIR/app/` → Upload `config.php`.
5. **Delete** both files from your PC (database password, push private key).
6. **Cron jobs:** cPanel → Cron Jobs. To find the right PHP binary, add a one-off job `php -v > $HOME/php-version.txt 2>&1; ls /opt/alt /opt/cpanel >> $HOME/php-version.txt 2>&1` every minute, read the file in File Manager, delete the job, then use a PHP 8.2+ path (e.g. `/opt/alt/php82/usr/bin/php`) in the three cron lines from §3.9.
7. **Later migrations:** phpMyAdmin → Import the new `database/migrations/00X_….sql`, then run `INSERT INTO schema_migrations (filename) VALUES ('00X_….sql');`. admin → System health shows any migration still pending.

## 4. `.cpanel.yml` (repo root)

The file in the repo is the source of truth (`DeployConfigTest` checks it). What it does on each deploy:

- reads `$APPDIR` / `$WEBDIR` from `~/mahavir_deploy.env` (spaces and Windows line ends ignored) and **stops** unless both folders already exist, so a typo never copies files somewhere new
- creates `storage/uploads`, `logs`, `backups`, `sessions`, `cron` under `$APPDIR`
- copies `app/` (never `config.php`), `lang/`, `vendor/`, `cron/`, `database/` to `$APPDIR` with `--delete`
- copies `order/` to `$APPDIR/order/` with `--delete`, protecting cPanel's `.well-known`, `.htaccess`, `cgi-bin`, `.user.ini`, `php.ini`, `error_log`
- copies `website/` to `$WEBDIR` **without** `--delete` (public_html also holds cPanel and Google files; delete a removed website page by hand)
- writes `$WEBDIR/approot.php` so the website finds `app/`

Notes:
- `tests/`, `tools/`, `docs/` are never deployed.
- If `rsync` is missing on the host (`which rsync` in cPanel Terminal), replace each line with `/bin/cp -R`.

**Deploying:** push to GitHub → cPanel → Git Version Control → Manage → Pull or Deploy → "Update from Remote" → "Deploy HEAD Commit". Then run new migrations: `php $HOME/mahavir/database/migrate.php` over SSH (or import the new `.sql` in phpMyAdmin).

## 5. Release checklist

- [ ] Tests pass locally
- [ ] Deployed to staging, migrations applied, manual test of the milestone checklist
- [ ] Merge to `main`, deploy production, apply migrations
- [ ] admin → System health (super-admin): crons OK, recent backup, no errors in today's log; check again after the first hour
- [ ] QR stickers and posters are printed only from production URLs (anywhere else they carry a "TEST – DO NOT USE" stamp)

First go-live: follow `docs/GO_LIVE.md` step by step.

**Restoring a backup:** backups are `storage/backups/mahavir-YYYY-MM-DD-HHMM.sql.gz` (last 30 nights). phpMyAdmin → select the database → Import → the file; or over SSH `gunzip -c FILE | mysql -u USER -p DBNAME`. The file recreates every table, so restore into the right database. Copies only live on this account: make sure the hosting plan has its own account backups, and download a backup to your PC now and then.

## 6. Counter PC setup (at the shop)

- [ ] Replace CMOS battery (CR2032); set correct date, time and time zone (India). Wrong date breaks HTTPS.
- [ ] Confirm Windows 7 SP1; Chrome 109 installed.
- [ ] Install Epson TM-T82 Windows driver; set it as **default printer**; paper 80 mm × receipt; print a Windows test page.
- [ ] Chrome: allow sound for `order.mahaveericecream.in`; turn off "Offer to translate"; turn off password-save prompts for the counter.
- [ ] Desktop shortcut "Mahavir Counter", target:
  `"C:\Program Files\Google\Chrome\Application\chrome.exe" --app=https://order.mahaveericecream.in/counter/ --kiosk-printing --autoplay-policy=no-user-gesture-required`
  (path may be `Program Files (x86)`). Copy it to the Startup folder so it opens on boot.
- [ ] Power options: never sleep, screen off after 30 min at most.
- [ ] Internet backup: keep a phone with mobile hotspot (or a 4G dongle) for when the shop internet drops. Connect the counter PC to it once and save the password, so staff only have to switch the hotspot on. If the PC has no Wi-Fi, use a USB Wi-Fi adapter or the phone's USB tethering. Test: switch off the shop router, switch on the hotspot, print a bill.
- [ ] Test: bill with Marathi item names prints clearly; KOT prints; beep is audible over shop noise.
- [ ] Reports (admin → Reports → Print) are A4 pages. The counter's Chrome prints silently to the receipt printer (`--kiosk-printing`), so print or save reports as PDF from the owner's phone or another PC, not from the counter shortcut.

## 7. Owner's phone (Android)

- [ ] Open `https://order.mahaveericecream.in/owner/` in Chrome and log in with the owner's username + password (the login returns to the owner page). Admin → "Owner phone page" also leads there.
- [ ] Chrome menu ⋮ → "Add to Home screen" (installs it like an app, icon "महावीर").
- [ ] Tap "सूचना चालू करा / Turn on notifications" and allow. Then "Send a test": a notification must appear within a few seconds.
- [ ] The notifications use the language the owner page shows on that phone (English / मराठी button at the top).
- [ ] Android settings → Apps → Chrome → Battery → Unrestricted (otherwise some phones delay notifications). Also check Chrome's notifications are allowed in Android settings.
- [ ] Lock the screen, place a test table order from another phone: the notification must arrive within a few seconds; tapping it opens the owner page at that order. Accepting it on the phone prints the KOT at the counter (the counter must be open).
- [ ] Mute switch: muted → no notification for the next test order; unmuted → notifications again.
- [ ] If notifications were blocked by mistake: Chrome ⋮ → Settings → Site settings → Notifications → allow `order.mahaveericecream.in`. Without notifications the page still checks every 10 s and beeps while it is open.

## 8. Website go-live checks (M8)

- [ ] Owner content in admin → Website content: About text (mr/en), gallery photos (the first visible photo is the home page photo). Until then the pages show "coming soon" placeholders (`TODO: owner content` in the code marks each one).
- [ ] Map: Google Maps → search the shop → Share → **Embed a map** → Copy HTML → paste into admin → Settings → "Google Maps Embed a map code". Also paste the shop's normal share link into "Google Maps link" (Directions button). Check the map shows on `/location.php` after tapping "Show the map".
- [ ] WhatsApp number in Settings only once the owner wants the button on the website.
- [ ] `APP_URL_WEBSITE` in production `config.php` is `https://mahaveericecream.in` (canonical and link-preview URLs). Staging pages carry `noindex` automatically (any `APP_ENV` other than `production`).
- [ ] `sitemap.xml` and `robots.txt` contain the production domain; submit `https://mahaveericecream.in/sitemap.xml` in Google Search Console.
- [ ] Lighthouse (Chrome DevTools → Lighthouse → Mobile, on the production URL): Performance ≥ 90, Accessibility ≥ 90, SEO ≥ 95 for the home and menu pages. Run it in an Incognito window (extensions skew the score).
- [ ] Share the home page link in WhatsApp once: the preview should show the shop name, description and photo.
