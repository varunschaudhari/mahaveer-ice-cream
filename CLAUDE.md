# Mahavir Ice Cream – Ordering & Billing System

Ordering, billing and website system for **Mahavir Ice Cream**, a 45+ year old ice cream parlour on Dhule Road, Amalner, Maharashtra (phone +91 99222 20000, open daily 10:00 AM – 10:30 PM).

One PHP + MySQL codebase, one database, three web roots:

| Part | Domain (production) | Folder |
|---|---|---|
| Public website | `mahaveericecream.in` | `website/` |
| Ordering app: takeaway QR, table QR, counter, owner phone, admin | `order.mahaveericecream.in` | `order/` |
| Shared PHP code (never web-accessible) | — | `app/` |

Staging: `mahaveer.foreverstory.in` (separate database copy).

## Read these before working

- `docs/SPEC.md` – what to build: features, roles, business rules, order statuses
- `docs/ARCHITECTURE.md` – folders, coding conventions, printing, polling, push, security, browser limits
- `docs/BUILD_PLAN.md` – milestones in order, with acceptance criteria
- `docs/PROGRESS.md` – what is done; **update it at the end of every session**
- `docs/DEPLOYMENT.md` – local setup, GitHub, cPanel deploy, counter PC setup
- `database/migrations/*.sql` and `database/seed.sql` – the schema and menu data are fixed; do not invent tables

## How to work in this repo

1. Work on **one milestone at a time** from `docs/BUILD_PLAN.md`. Do not start the next milestone until the current one's acceptance criteria pass and the developer confirms.
2. Before writing code for a milestone, state a short plan (files to create/change) and wait for approval if the plan changes the schema or folder structure.
3. After each milestone: run the tests, update `docs/PROGRESS.md`, and suggest a git commit message.
4. If something in the docs is unclear or contradictory, **ask** instead of guessing. Business rules come from the shop owner.
5. Schema changes go in a new numbered migration file (`database/migrations/00X_description.sql`); never edit an applied migration.

## Hard constraints

- **Plain PHP 8.2+** (production runs the latest PHP on cPanel; code must also run on PHP 8.2). No frameworks (no Laravel, no Symfony).
- **MySQL 8 / MariaDB 10.6+**, InnoDB, `utf8mb4` everywhere. PDO with prepared statements only.
- **Plain hand-written CSS** and **vanilla JavaScript**. No CSS frameworks, no npm, no build step, no bundlers.
- **No Composer on the server.** Runtime libraries are installed locally with `composer install --no-dev` and the `vendor/` folder **is committed**. Only add a Composer package if the docs name it or the developer approves.
- PHPUnit runs from `tools/phpunit.phar` (not a Composer dependency).
- **Counter PC runs Windows 7 + Chrome 109 (cannot update).** Every page under `order/counter/` and every print page must work in Chrome 109. See the browser rules in `docs/ARCHITECTURE.md`.
- No real page routing/rewrites: each page is a real `.php` file, so PHP's built-in server works locally and cPanel needs no rewrite rules.
- Money is calculated in **integer paise** in PHP (₹40 = 4000), stored as `DECIMAL(10,2)` in MySQL. Never use floats for money.
- Time zone `Asia/Kolkata` in PHP and `+05:30` in MySQL sessions.
- Marathi is the default language, English is the toggle. Never hard-code UI text: use `t('key')` with `lang/mr.php` and `lang/en.php`.

## Out of scope – do not build (phase 2)

Razorpay/online payments, WhatsApp messaging, SMS, OTP login, home delivery, loyalty/coupons, GST invoicing, Play Store app, customer token display screen, raw-material inventory, Zomato/Swiggy. The schema already has unused columns for Razorpay; leave them.

## Commands (Windows 11, run from repo root)

```bash
# local servers (MySQL from XAMPP must be running)
php -S localhost:8000 -t order
php -S localhost:8001 -t website

# database
php database/migrate.php            # applies new migrations in order
php database/migrate.php --seed     # also loads seed.sql (fresh DB only)
php database/migrate.php --fresh    # DROP + recreate + seed (local only, refuses if APP_ENV=production)

# tests (uses database mahavir_test)
php tools/phpunit.phar

# runtime libraries (only when adding/updating, then commit vendor/)
composer install --no-dev --optimize-autoloader
```

## Code style

- `declare(strict_types=1);` at the top of every PHP file in `app/`.
- Escape all output with `e()` (wrapper around `htmlspecialchars`). Never echo raw user input.
- Every POST form and every state-changing API call checks a CSRF token.
- Business logic lives in `app/Services/*.php` classes, not in page files. Page files only: load bootstrap, check auth, call services, render.
- Keep functions small and name things in English. Comments only where the "why" is not obvious.
- Files and folders: `snake_case.php` for pages, `PascalCase.php` for classes.
