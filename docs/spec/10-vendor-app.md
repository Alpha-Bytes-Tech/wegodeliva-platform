# 10. Vendor app and vendor web portal — screens

App name **WeGoDeliva Vendor** (keep the live package/bundle ID). The vendor web portal (Next.js) offers the same functions on desktop, with richer catalogue management (bulk edit, CSV import — SHOULD).

| Screen | Purpose / behaviour |
| --- | --- |
| Splash / onboarding / force-update (old) | As customer app, vendor audience. |
| Login (old) | Phone OTP or email + password. Rejects users without a vendor role or without an assigned vendor. |
| Register vendor (old) | Owner name, phone, email, password; business name, type, phone, email, address with map pin; documents (CAC, licence for pharmacies, ID) — count and instructions from settings. Result: "Pending approval" screen. |
| Pending approval / document requests (old) | Shows what admin asked for; upload more documents; status of each. |
| Vendor switcher (old) | For staff linked to several vendors. |
| **Order inbox** (old home, redesigned) | Tabs: New / Preparing / Ready / Out for delivery / Done. New orders **ring until opened** (sound + vibration, works in background). Order card: code, items count, total, payment (paid / pay on delivery), fulfilment, scheduled time, waiting timer. |
| Order details (old) | Items with options and notes, customer first name (phone masked/call button if allowed), delivery address area, prescription images for pharmacy orders. Actions by status: **Accept with prep time** (preset 10/15/20/30/45 min + custom) / **Reject with reason**; **Mark ready**; for self-pickup: **Hand over** with the customer's code; assign own rider (if vendor uses own riders); **Print receipt**; call rider. Pharmacy review: edit items/quantities/prices, add note, send price to customer. |
| Store status (new) | Open / Busy (longer prep, or stop new orders for X minutes) / Closed; shows schedule-driven state and manual override with auto-return to schedule. |
| Opening hours (old: admin side) | Weekly hours, multiple shifts per day, holiday closures (SHOULD). |
| Products (old) | List with search, filter by menu/category, toggle active, quick **stock** edit, out-of-stock toggle. |
| New / edit product (old) | Name, description, images (max count & size from settings), price, promo price, unit/size, stock (unlimited or quantity), categories/subcategories, menu sections, tags, option groups, flags (featured, prescription required, age-restricted, deliverable). |
| Menus, option groups, options (old: admin web; vendor portal) | Manage sections and add-ons. |
| Parcel rates (old: package type pricing) | Parcel operators only: per package type base/distance/size/extra-stop prices, flags. |
| Earnings (new screen; old had earnings in web panel) | Balance, today/week/month totals, per-order earnings ledger (gross, commission, net), statements (PDF/CSV download — SHOULD). |
| Payout accounts (old) | Add/edit bank account with name verification. |
| Payouts (old web; new in app) | Request payout, history with statuses. |
| Reviews (new in app) | Customer reviews and ratings; reply (LATER). |
| Staff (new) | Owner invites/removes staff by phone. |
| Own riders (old web: vendor drivers) | Invite riders, see who's online (Q8 confirmed). |
| Promotions (SHOULD) | Create vendor-funded coupons within platform limits. |
| Notifications inbox (old) | |
| Profile / vendor details (old) | Logo, cover, description, contact, address (location change requires admin re-approval — SHOULD), delivery settings (pickup/delivery, min order, prep time, auto-accept, scheduled orders), payment methods accepted. |
| Change password / delete account (old) | Owner deletion blocked while vendor has balance or open orders. |
| Receipt printing (new, CLAUDE.md) | Bluetooth thermal printer (58/80 mm, ESC/POS): pair printer, auto-print on accept (toggle), reprint. Android 12+ runtime Bluetooth permissions. |
| Support (new) | WhatsApp to vendor-support line, FAQ, report a problem. |
| Chat (in scope at launch) | Per-order chat with the customer (and with the assigned rider — SHOULD); support chat with WeGoDeliva. |

## Services (in scope from 2026-11-01)

| Screen | Purpose / behaviour |
| --- | --- |
| Services list / new / edit | Name, category, description, images, pricing unit and price, min/max units, location mode, call-out fee, capacity, duration, options, active toggle. |
| Availability | Working hours per weekday, blocked dates, capacity per slot. |
| Booking inbox | Requested / Confirmed / Today / Completed. Accept or decline (reason) requested bookings; calendar view (SHOULD). |
| Booking details | Customer, location, time, options, note; chat; **Start** (enter the customer's start code) → in progress; **Complete**; collect payment if pay on completion. |

## Tax details (2026-11-01, R3)

| Screen | Purpose / behaviour |
| --- | --- |
| Tax settings (registration and profile) | VAT registered yes/no, TIN, VAT certificate upload (admin verifies). Changes need admin re-approval. |
| VAT statement | Per period: standard / zero-rated / exempt sales, VAT collected (for the vendor to remit), and VAT paid on WeGoDeliva commission (input VAT). |
