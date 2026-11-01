# 12. Admin panel (Next.js, Vercel)

Login: email + password + TOTP. All writes audited. Zone admins see only their zones. Tables everywhere: search, filters, sort, pagination, CSV export (exports of personal data are restricted to super admin and logged).

## 12.1 Dashboard
- Today / 7 days / 30 days: orders, GMV, delivered, cancelled (by reason), average delivery time, new customers, active vendors, online riders, revenue (commission + delivery margin + platform fees).
- Live board: orders by status with ages; alerts (vendor not accepting, no rider found, failed deliveries, payment mismatches, webhook signature failures).
- Map: online riders and active orders (SHOULD).

## 12.2 People
- **Customers**: list, details (orders, wallet, addresses, referrals, tickets), suspend/reactivate, reset password link, manual wallet adjustment (reason required), deleted-users list.
- **Vendors**: list by type/zone/status; create/edit; approve/reject with notes; request documents; view documents (signed URLs); set commission rate, fees, delivery settings, zones, payment methods, hours; feature/sort; suspend; staff members; earnings.
- **Riders**: list (online now, approved, pending); approve/reject; documents; vehicle; rating; jobs; earnings; COD balance and remittance confirmation; suspend; set personal share rate.
- **Admins & roles** (super admin): create admins, zone admins, permissions per role.

## 12.3 Orders
- List with filters (status, type, vendor, rider, zone, payment method/status, date) and live updates.
- Order details: timeline (status history with actors), items, price breakdown, payments and gateway verification results, refunds, rider offers history, map of route, attachments (prescription, proof photos), notes.
- Actions: cancel (reason), mark failed, assign / reassign rider, force status (audited), full or partial refund, resend notifications, add internal note.
- Create order on behalf of a customer (old feature: "new order" pages for regular and parcel) — SHOULD, for phone orders.
- Print order (old) — receipt view.

## 12.4 Catalogue & content
- Vendor types, categories, subcategories, tags, banners (zone-targeted, scheduled), package types.
- Products across vendors (moderation, deactivate).
- Content pages (terms, privacy, about), FAQs per audience, onboarding slides per audience.
- Cancellation reasons per audience.

## 12.5 Zones & fees
- Draw/edit zone polygons on a map; mark campus zones; default delivery fee; rider search radius.
- Platform delivery defaults (base fee, per-km fee, max distance).
- Service fees (create, assign to vendors).
- Parcel rates per operator (also editable by operator).

## 12.6 Promotions
- Coupons: create/edit with all rules in [05](05-pricing-fees-commission.md) §5.4; usage report.
- Referral programme settings and report (referrals, rewards paid, voids).
- Broadcast push notifications by audience/zone with optional deep link; history.
- Flash sales / loyalty — LATER (Q2, Q4); not built at launch.

## 12.7 Finance
- Payments: all gateway payments with verification status; webhook event log; mismatches.
- Wallet transactions (all users), with adjustments.
- Refunds queue: pending/failed refunds, process to wallet or original method.
- Earnings: vendor and rider balances and ledgers.
- Payouts queue: approve/reject/mark paid/send via transfer API; failed transfers.
- Rider cash (COD) balances and remittance confirmations.
- Commission report per order / vendor / period (old report). Exports for accounting.

## 12.8 Reports (old reports kept where useful)
- Sales by vendor, product, category, zone, period.
- Customer report (new vs returning, top customers).
- Coupon report, referral report, commission report, rider performance (acceptance rate, delivery time, ratings), vendor performance (acceptance time, rejection rate, ratings).

## 12.9 Support
- Tickets from in-app "report a problem": assign, reply (push to user), resolve, link to refund.
- Reviews moderation (hide abusive reviews).

## 12.10 Settings (super admin) — see [13-settings.md](13-settings.md)
- General, ordering, delivery & dispatch, finance, notifications templates, app versions & force update, support contacts, feature flags, legal.

## 12.11 System
- Audit log viewer (filter by actor, subject, action).
- Queue/job health and failed jobs (read-only; retry button for safe jobs).
- Scheduler heartbeat (last run of each job).
- Old template pages for backups, upgrades, server config, translations editor, purchase-code, cron URL — **not built** (O7).
