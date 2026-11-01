# 1. Scope

## 1.1 Product

WeGoDeliva is a multivendor, on-demand delivery and services marketplace in Nigeria, starting with Lagos and university campuses in the South-West and South-South. Customers order from independent vendors; WeGoDeliva riders, independent riders or a vendor's own riders deliver. Customers can book a rider to carry a parcel from A to B, and book service providers (e.g. cleaning, laundry, hair, repairs).

Three mobile apps (Customer, Vendor, Rider), a web admin panel, and a vendor web portal all talk to one API.

## 1.2 Launch campuses (owner decision 2026-11-01)

Each campus is a **campus zone** (polygon) inside a **city**. Campus mode turns on when the customer's location is inside one.

| Campus | City / State |
| --- | --- |
| University of Lagos (UNILAG), Akoka | Lagos |
| Lagos State University (LASU), Ojo | Lagos |
| Yaba College of Technology (YABATECH) | Lagos |
| Lagos State University of Science and Technology (LASUSTECH), Ikorodu | Lagos |
| University of Ibadan (UI) | Ibadan, Oyo |
| Federal University of Agriculture, Abeokuta (FUNAAB) | Abeokuta, Ogun |
| Olabisi Onabanjo University (OOU), Ago-Iwoye | Ago-Iwoye, Ogun |
| Obafemi Awolowo University (OAU), Ile-Ife | Ile-Ife, Osun |
| University of Benin (UNIBEN) | Benin City, Edo |

Consequence: the platform is **multi-city within Nigeria** (still NGN only, one country). Zones belong to a city; vendors, riders, coupons, banners, reports and zone admins can be scoped by city or zone. Campus boundaries will be drawn by ops in the admin panel (Phase 6) — the team needs a map pin or boundary sketch per campus.

## 1.3 Verticals in scope at launch

Vendors are grouped by **vendor type**; each type switches on a shopping flow. All six types are in scope at launch (owner decision 2026-11-01).

| Vendor type | Flow | Notes |
| --- | --- | --- |
| Food | Menu → cart → checkout. Items can have add-ons/options (e.g. "extra protein", "drink choice"). Prep time matters. | Old system: "food". |
| Groceries | Catalogue with categories and subcategories, stock quantities, units (kg, pack). | Old system: "grocery". |
| Pharmacy | Like groceries, plus **prescription upload**: the customer may attach photos of a prescription instead of (or as well as) choosing items. An order with a prescription goes to "awaiting review" until the pharmacy prices it. Some items are age- or prescription-restricted. | Old system: "pharmacy". |
| Fashion / general shopping | Product catalogue with variants (size, colour) expressed as option groups; richer product pages (several images). | Old system: a "commerce" type. |
| Parcels | No catalogue. Customer enters pickup, one or more drop-offs, a package type and size/weight; price is computed from distance and size. The "vendor" here is a delivery operator (WeGoDeliva itself, or a partner courier). | Old system: "parcel"/"package". |
| Services | Customer books a service from a provider (laundry, cleaning, hairdressing, repairs, tutoring…). A service has a price per unit of time or a fixed price, optional add-on options, and is performed at the customer's address or at the provider's location. No rider unless the service involves pickup (e.g. laundry pickup & return — LATER). See [04](04-order-lifecycle.md) §4.8. | Old system: "service"/"booking". Was proposed out (O2); **brought into scope by owner 2026-11-01**. |

## 1.4 Platform features in scope

- Customer accounts: **phone and email both required**; phone OTP and email/password login; **Sign in with Google and Apple at launch** (phone and email still collected).
- Vendor onboarding with document upload and admin approval.
- Rider onboarding with documents and admin approval. Three rider types: **WeGoDeliva-managed, independent, vendor-owned**.
- Cities and delivery zones (polygons), campus zones; zone-based or distance-based delivery fees.
- Vendor opening hours, manual open/closed/busy, auto open/close by schedule.
- Single-vendor cart, scheduled orders, delivery or self-pickup, rider tip, order notes.
- Service bookings with date/time slots.
- Coupons (vendor-funded by default; platform-wide, per vendor, per vendor type, per product, or delivery-fee coupons).
- Service fees per vendor (fixed or percentage); VAT on goods (not on delivery or service fees).
- Payments: Paystack, Flutterwave (card, bank transfer, USSD), wallet, pay on delivery (all vendors and zones, max ₦20,000 per order).
- Customer wallet (closed-loop, CBN KYC tiers, no withdrawal to bank) with top-up, history, refunds into wallet.
- Vendor and rider earnings; commission deducted when they withdraw; payouts on request via Paystack/Flutterwave Transfers.
- Rider matching and manual assignment; live tracking.
- Proof of delivery: **handover code on every delivery**.
- Ratings and reviews of vendors, riders, products and services.
- Favourites, search, banners, categories, tags.
- Referral reward: 5% of the referred user's first purchase, capped at ₦5,000, to the referrer's wallet.
- **In-app chat at launch** (customer ↔ vendor, customer ↔ rider, user ↔ support) on Supabase Realtime. WhatsApp support link once the number is provided.
- Push notifications (FCM, existing Firebase project), SMS OTP, transactional email.
- Admin panel: dashboards, users, vendors, riders, approvals, orders, bookings, refunds, payouts, cities and zones, fees, promos, content, settings, audit log, reports, chat/support inbox.

## 1.5 Out of scope — confirmed by owner 2026-11-01, amended the same day

Changing any line is a scope change and needs the owner's approval.

### Out

| # | Module | What it did in the old system | Why out |
| --- | --- | --- | --- |
| O1 | Taxi / ride-hailing | Book a car ride; vehicle types with base/distance/time fares; taxi zones; car makes & models; per-currency taxi pricing; driver switch between "taxi" and "delivery" modes; trip fare recalculation and outstanding balances | Not a delivery-marketplace feature. |
| ~~O2~~ | ~~Service booking~~ | — | **Moved into scope** (§1.3). |
| O3 | Payment gateways other than Paystack and Flutterwave | Stripe, PayPal, Razorpay, Paytm, PayU, Billplz, Abitmedia (iPay88, named in CLAUDE.md, was not present) | Not used in Nigeria. |
| O4 | SMS gateways not needed | Twilio, Hubtel, Africa's Talking, GatewayAPI, Msg91, Firebase phone auth | Keep **one** Nigerian SMS provider (proposal: Termii, not yet set up) plus one fallback. |
| O5 | Multi-country / multi-currency | Countries/states/cities tables per vendor; currency table; per-order currency | Nigeria and NGN only. Cities within Nigeria **are** supported (§1.2) via a simple cities table + zones. |
| O6 | Firebase Firestore, Dynamic Links, Firebase Auth | Rider locations, offers and chat in Firestore; share links via Dynamic Links | FCM only. Realtime and chat on Supabase Realtime; links via App Links / Universal Links. |
| O7 | Template self-management | System upgrade runner, purchase-code licence check, extensions, backup/restore page, troubleshooting page, HTTP-triggered cron, dev data generators, auto-translator, server settings page | Replaced by CI/CD, hosting and Laravel scheduler. |
| O8 | QR-code login | Log into the web panel by scanning a QR code | Adds attack surface. |
| O9 | Fleet management | Fleet companies owning riders and vehicles | Not part of WeGoDeliva's model. |
| O10 | Digital products | Downloadable files | WeGoDeliva delivers physical goods and services. |
| O11 | Hosted payment pages for dropped gateways | — | Only Paystack/Flutterwave checkout remains. |

### Decisions on the optional modules

| # | Module | Decision |
| --- | --- | --- |
| Q1 | Vendor subscription plans | Out for launch; commission only. |
| Q2 | Loyalty points | LATER. |
| Q3 | Wallet-to-wallet transfers | Out (CBN/KYC). |
| Q4 | Flash sales | LATER. |
| Q5 | In-app chat | **In at launch** on Supabase Realtime (owner decision, replaces "LATER"). |
| Q6 | Multiple languages | English only at launch, built with gen-l10n. |
| Q7 | Multi-vendor cart | Out; single-vendor cart. |
| Q8 | Vendor-owned riders | In (one of three rider types). |
| Q9 | "Offline" manual bank-transfer payment | Out. |
| Q10 | Facebook login | Out; Google + Apple in. |
| Q11 | Rider prepaid wallet | Replaced by rider cash-on-delivery balance. |
| Q12 | Barcode scanning | Fields only; no scanner UI. |
