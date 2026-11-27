# Functional Specification – V1

## 1. The shop

- Name: Mahavir Ice Cream (महावीर आईस्क्रीम), tagline on menu: "Adding flavors to your life" (as on the logo)
- Address: Dhule Road, near Pach Pauli Mandir, Hanuman Nagar, Amalner, Maharashtra 425401
- Phone: +91 99222 20000
- Hours: daily 10:00 AM – 10:30 PM. Busiest Saturday and Sunday evenings.
- 7 dine-in tables: T1–T6 inside, T7 outside.
- Counter: Essae touchscreen PC (Windows 7, Chrome 109) + Epson TM-T82 80 mm thermal printer.
- Not GST registered: simple bills with no GSTIN or tax lines.
- Sells its own ice creams, lassi, shakes, shrikhand, plus packaged Havmor items (Kulfi section).
- Menu board note: "समयपर जो माल तैयार होगा वही मिलेगा" (only what is ready will be available) → daily sold-out toggle is essential.
- Menu board note: "पार्सल चार्जेस एक्स्ट्रा" → ₹5 parcel charge per takeaway order.

## 2. Users and roles

| Role | Device | Login | Can do |
|---|---|---|---|
| Customer | Own phone | None. Name + 10-digit mobile per order | Order takeaway, order at table, bulk order, track status |
| Staff (4 at start) | Counter PC | 4-digit PIN | Bill walk-ins, accept/handle online and table orders, toggle stock, settle tables, reprint |
| Owner | Counter PC + Android phone | Username + password (and a PIN for approvals at the counter) | Everything staff can do + menu, prices, settings, reports, bulk approval, cancellations, staff, customers, website content |
| Super-admin (developer) | Anywhere | Username + password | Everything + system settings |

Counter login: tap your name, then enter your 4-digit PIN (PINs do not need to be unique).
PIN/password: 5 wrong attempts → that account is locked for 15 minutes; the owner can unlock it from admin. The counter never auto-locks; staff tap **Lock**.

## 3. Order types and statuses

All orders live in one `orders` table with `order_type`:

| Type | Created by | Status flow |
|---|---|---|
| `counter` | Staff at counter | Saved directly as `completed` (paid at billing) |
| `takeaway` | Customer via takeaway QR / website | `received` → (staff Accept) `preparing` → (Ready) `ready` → (Picked up + payment collected) `completed` |
| `table` | Customer via table QR | `received` → (Accept, KOT prints) `preparing` → (Served) `served` → `completed` when the table is settled |
| `bulk` | Customer via bulk form | `pending_approval` → (owner approves, sets advance) `preparing` → `ready` → `completed` when balance is paid |

Any order can go to `cancelled` (owner PIN needed once it is `preparing` or later; reason required). Takeaway orders not picked up 30 minutes after their slot ends go to `no_show` automatically (cron).

`pending_payment` exists in the schema for phase 2 (Razorpay); do not use it in V1.

## 4. Numbers

- **Token number:** resets to 1 every day. One shared series for counter, takeaway, table and bulk orders. Generated inside a transaction with `SELECT ... FOR UPDATE` on `daily_tokens`.
- **Bill number:** continuous, never resets, never reused, one series for everything (sequence row `bill_no` in `sequences`). Assigned when money is finally settled:
  - counter order: when saved
  - takeaway: when marked picked up and paid
  - bulk: when balance is paid
  - table: once per table session at settlement (stored on `table_sessions.bill_no`; individual table orders keep `bill_no` NULL)
- A cancelled bill keeps its number and stays in the records marked Cancelled.

## 5. Pricing rules

- Prices come from `item_variants.price`. Each order line copies `item_name` and `unit_price` at sale time; price changes never affect old orders.
- Parcel charge: `settings.parcel_charge` (₹5) once per takeaway order, and on counter bills when staff choose "Parcel". Never on table orders. Bulk orders: no parcel charge.
- Discount: owner only, flat ₹ amount per order, reason required (V1 keeps it simple).
- A variant with price 0 can never be added to any cart (placeholders for the Kulfi section).
- `total = round_to_rupee(subtotal + parcel_charge − discount)`, computed in paise. Line amounts keep exact paise (only decimal bulk quantities produce paise); the total rounds to the nearest ₹1, 50 paise and above rounding up. The difference is printed as a "Round off" line when it is not zero. It is not stored: round off = `total − (subtotal + parcel_charge − discount)`.

## 6. Takeaway ordering (customer phone)

URL: `order.mahaveericecream.in/` (takeaway QR on door/counter poster, links from the website).

1. Menu by category with Half/Full or weight variants. Sold-out items greyed and not addable. Inactive items hidden.
2. Cart stored in the browser (`sessionStorage`) until checkout. Shows subtotal, ₹5 parcel charge, total.
3. Checkout: pickup slot (the earliest free slot is selected already; the customer can tap another – client decision 2026-11-27), name, mobile (10 digits, starts 6–9), optional note. Payment: "Pay at counter on pickup" (only option in V1).
4. Pickup slots: 15-minute slots for **today only**, earliest = now + 15 min rounded up to the next slot, last slot ends 30 min before closing (so last pickup slot 9:45–10:00 PM). A slot with 10 or more active takeaway orders is full.
5. Ordering closed outside hours: banner "दुकान बंद आहे, सकाळी 10:00 वाजता उघडेल" / "Shop closed, opens at 10:00 AM".
6. Blocked mobile numbers (2+ no-shows) see: "Please order at the counter or call the shop."
7. After placing: order status page `order.php?id=<id>&k=<order_key>` shows token, items, total, pickup slot, live status (polls every 10 s): Received → Confirmed/Preparing → Ready → Picked up / Cancelled.
8. "My recent orders" on the menu page: this phone's own last 5 orders, remembered in a cookie that holds each order's id + secret key (never the mobile number, so nobody can see another person's orders by typing their number); reorder button refills the cart with currently available items. Name and mobile are pre-filled from the phone.
9. The customer can cancel their own order from the status page while it is still Received (not yet accepted); after that they call the shop.

## 7. Table ordering (customer phone)

URL: `order.mahaveericecream.in/t.php?t=<32-char token>` printed as a QR sticker on each table.

1. Menu opens with "टेबल 4 / Table 4" at the top. No slot, no parcel charge.
2. First order of the visit asks name + mobile; the phone remembers that it already ordered in this visit (browser storage) and does not ask again. Any phone scanning the table's QR joins the table's open visit (one running bill per table); each new phone gives its name + mobile once.
3. Payment: at the counter before leaving (cash or the shop's UPI QR).
4. Customer can order again any time; each order adds to the table's running bill (the open `table_sessions` row for that table).
5. Buttons: **Call staff** and **Request bill** (create `table_alerts`; Request bill also sets session status `bill_requested`).
6. Each order shows status: Received → Preparing → Served.
7. Invalid or regenerated token → friendly error "Please scan the QR on your table again or ask staff."
8. If a table has an open session older than `table_unsettled_mins` (120), the counter shows a warning on that table tile.

## 8. Bulk / party orders

URL: `order.mahaveericecream.in/bulk.php` (also linked from website).

- Customer enters items and quantity (packs, kg; quantity can be decimal), event date (at least 1 day ahead, `settings.bulk_notice_days`), pickup time, name, mobile, note. No minimum.
- Status `pending_approval`; owner sees it in admin and on his phone.
- Owner approves (sets `advance_amount`, can edit items/prices) or rejects with a reason.
- Advance is paid at the shop or to the shop's UPI QR; staff records it as a payment row. Balance collected at pickup.
- Sold by weight: the owner adds "1 किलो / 1 kg" variants with a price to the flavours sold per kg; the customer orders e.g. 2.5 × "1 kg". These variants also show as family packs on the menu and counter (decision 2026-11-26).
- Staff handle approved bulk orders on the counter's **Bulk** tab (record advance, Ready, Picked up → balance → bill); the owner approves / edits / rejects in admin. The customer follows the order on the same status page as takeaway, may cancel while it is still pending, and sees the owner's reason if it is rejected.

## 9. Counter screen (Essae touchscreen PC)

Opened as a Chrome app window: `chrome.exe --app=https://order.mahaveericecream.in/counter/ --kiosk-printing --autoplay-policy=no-user-gesture-required`.

Layout (landscape, 1024×768 minimum, touch only, no hover-dependent UI):

- **Billing tab:** category buttons → item grid → variant buttons (Half/Full). Cart on the right. Dine-in / Parcel toggle (every new bill starts on **Dine-in**). Payment: Cash / UPI / Mixed (split amounts), cash tendered → change shown. Save & Print → **one copy** of the bill with token prints. Big buttons (min 56 px tall).
- **Online orders tab:** takeaway + table orders in arrival order, newest highlighted. Each card: token, type, table number (very large for table orders, e.g. "TABLE 4") or pickup slot, items, total, age; a slot whose window has ended while the order is still open is marked "Late". Buttons by status: Accept / Ready / Picked up (collect payment) / Served / Cancel.
- **Tables tab:** 7 tiles (T7 labelled Outside). Each tile: Free / Occupied / Bill requested, running total, minutes since first order, open alerts. Tap → table detail: orders, Mark served, Move order to another table, Settle (combined bill → payment → print → table Free).
- **Stock tab:** every item with an on/off switch for today's availability.
- **Today tab:** today's bills, reprint, cancel (owner PIN), day close summary (totals by Cash / UPI counter, counts by order type), print day summary.
- Alerts: new takeaway/table order, Call staff, Request bill → sound (Web Audio beep, repeated every 10 s until acknowledged) + red badge on the tab.
- Sound: Chrome blocks audio until the page is tapped. The counter shortcut adds `--autoplay-policy=no-user-gesture-required` so alerts always sound; without it, a small "Tap anywhere to turn on sound" hint shows and the first tap anywhere unlocks audio (non-blocking instead of a full-screen "Tap to start" overlay, because each tab is its own page).
- Staff switch: "Lock" button returns to the name + PIN screen (no auto-lock); every order records `staff_id`.
- No internet: when requests to the server fail, a red full-width banner "इंटरनेट नाही – मोबाइल हॉटस्पॉट चालू करा" / "No internet – switch on the mobile hotspot" appears, and Save & Print is disabled until the connection is back. Backup connection is a phone hotspot (DEPLOYMENT.md §6); there is no offline billing in V1.

## 10. Printing (80 mm, Epson TM-T82)

- **Bill:** shop name (Marathi + English), address, phone, bill no, token (large), date/time, order type (Dine-in / Parcel / Table 4 / Takeaway), items (name and variant **in Marathi only**, qty, rate, amount), subtotal, parcel charge, discount, round off (only if not zero), total, payment mode(s), staff name, footer "धन्यवाद! पुन्हा भेट द्या" / "Thank you, visit again".
- **Reprint:** a reprinted bill shows "DUPLICATE / प्रत" at the top and is recorded in `audit_log` (who, when).
- **KOT** (table orders, on Accept): "TABLE 4" in 40 px+ bold, order no, time, items and quantities, note. No prices.
- **Token slip:** included at the top of the bill; no separate slip in V1.
- **Day summary:** date, totals by payment mode, counts by order type, cancelled bills list.

## 11. Owner's phone (Android, Chrome)

URL: `order.mahaveericecream.in/owner/` installable to the home screen (PWA manifest).

- Live orders list: all active takeaway, table and bulk orders, table number large.
- Web Push notification for every new takeaway, table and bulk order (works with the screen locked). Mute switch.
- Accept / Cancel / Approve bulk from the phone.
- Today's sales total at a glance.
- Fallback when push is blocked: page polls every 10 s with sound while open.

## 12. Admin panel (owner)

URL: `order.mahaveericecream.in/admin/`. Works on the counter PC and the owner's phone.

- Menu: categories, items (Marathi + English name, photo, sort order, active, online enabled), variants (labels, price, sort order). Activating an item with a ₹0 variant is refused.
- Settings (table `settings`): hours, last order minutes, parcel charge, slot length and capacity, minimum lead time, no-show auto-cancel minutes, no-shows before block, bulk notice days, table warning minutes, bill header/footer text.
- Tables: list T1–T7, area, active, regenerate QR token, print QR stickers (A4 sheet, each sticker shows table number + QR + "Scan to order").
- Takeaway QR poster: A4 printable.
- Bulk orders: approve/reject, advance, balance.
- Staff: add, set/reset PIN, deactivate, role.
- Customers: name, mobile, order count, last order, no-show count, block/unblock.
- Reports (date range): sales by day, item-wise quantity and amount, top flavours, payment-mode split, order-type split, staff-wise bills, cancelled bills. CSV export for each.
- Website content: About text (mr/en), announcement banner (mr/en, on/off), gallery photos.
- Audit log viewer: cancellations, discounts, price changes, settings changes, bill reprints.

## 13. Public website (`mahaveericecream.in`)

Mobile-first, fast on slow phones, Marathi default + English toggle. No cart; all order buttons go to `order.mahaveericecream.in`.

| Page | Content |
|---|---|
| Home (`index.php`) | Hero photo, "45+ years in Amalner", best sellers (Badam Shake, Mahavir Special, American Dryfruit), Order takeaway + Bulk order buttons, announcement banner if on |
| About | Family story (from `site_content`) |
| Menu | Live from DB: categories, Half/Full prices, sold-out badges; ₹0 and inactive items hidden |
| Bulk & party orders | How it works, 1 day notice, button to bulk form |
| Gallery | Photos from `site_photos` |
| Location & hours | Address, embedded Google Map (owner provides link), hours, phone |
| Contact | Call button (`tel:`), WhatsApp button hidden until a number is set in settings |

SEO: unique title/description per page (include "ice cream Amalner"), `LocalBusiness` JSON-LD (name, address, phone, hours, geo), `sitemap.xml`, `robots.txt`, Open Graph tags. Images WebP, lazy-loaded, width/height set.

Content still to come from the owner: logo file, photos, founding year and family story, Google Maps link. Use clearly marked placeholders until then.

## 14. Business rules (all editable in Settings)

| Key | Default | Meaning |
|---|---|---|
| `open_time` / `close_time` | 10:00 / 22:30 | Shop hours |
| `last_order_mins` | 30 | Online ordering stops this many minutes before closing |
| `parcel_charge` | 5 | ₹ per takeaway order |
| `slot_mins` | 15 | Pickup slot length |
| `slot_capacity` | 10 | Max active takeaway orders per slot |
| `min_lead_mins` | 15 | Earliest pickup from now |
| `noshow_after_mins` | 30 | Auto no-show after slot end |
| `noshow_block_count` | 2 | No-shows before a mobile is blocked |
| `bulk_notice_days` | 1 | Minimum days ahead for bulk orders |
| `table_unsettled_mins` | 120 | Warn on counter after this |
| `whatsapp_number` | empty | Website WhatsApp button hidden when empty |

## 15. Language

- Default Marathi; toggle stored in a cookie `lang` (`mr` / `en`).
- Item names from `name_mr` / `name_en`. UI strings from `lang/*.php`.
- Numbers stay in Western digits (1, 2, 3) everywhere, including bills, for easy reading by staff.
- Font: Noto Sans Devanagari (self-hosted woff2) for screen and print.

## 16. Branding

**Updated 2026-11-27 (client decision):** the shop's own logo, and a "modern gelato boutique" look for the website, the customer ordering pages, admin and the owner's phone page.

- Name in English: **Mahavir Ice Cream**, as on the logo (Marathi महावीर आईस्क्रीम). Tagline as on the logo: "Adding flavors to your life".
- Logo: the client's logo (`docs/brand/mahavir-logo-original.jpg`, 1048×771 JPG on white). `docs/brand/build_logo.ps1` only removes the white around it and cuts it into parts, never redraws or recolours it:
  - full logo: hero without a photo, admin login, link preview
  - without the tagline line: website header and footer, ordering hero
  - the swirl-and-M on top: small app bars, placeholders, app / browser icons, the white notification badge
  - When the designer's original file arrives, export it large on white and run the script again.
- Palette: paper `#FBF8F3`, ink `#1F1A17` (main buttons), muted `#7D746D`, hairlines `#E8E1D8`, one accent in logo pink: `#D81B7F` (a shade deeper than the logo's `#F8208F`, so text passes contrast). Pastels from the logo colours behind photos (pink, yellow, lime, sky blue, purple, vanilla). Headings in Martel (serif, self-hosted), body in Noto Sans Devanagari.
- The counter keeps its own high-contrast till colours (magenta / green Cash / purple UPI) and only uses the logo mark.
- Keep colours as CSS custom properties so they can change in one place.
