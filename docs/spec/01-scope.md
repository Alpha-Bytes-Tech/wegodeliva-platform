# 1. Scope

## 1.1 Product

WeGoDeliva is a multivendor, on-demand delivery marketplace for Lagos. Customers order from independent vendors; WeGoDeliva riders (or a vendor's own riders) deliver. Customers can also book a rider to carry a parcel from A to B. The rollout starts on university campuses ("campus mode").

Three mobile apps (Customer, Vendor, Rider), a web admin panel, and a vendor web portal all talk to one API.

## 1.2 Verticals in scope

The old system grouped vendors by a **vendor type**, and each type switched on a different shopping flow. WeGoDeliva keeps this idea with five types:

| Vendor type | Flow | Notes |
| --- | --- | --- |
| Food | Menu → cart → checkout. Items can have add-ons/options (e.g. "extra protein", "drink choice"). Prep time matters. | Old system: "food". |
| Groceries | Catalogue with categories and subcategories, stock quantities, units (kg, pack). | Old system: "grocery". |
| Pharmacy | Like groceries, plus **prescription upload**: the customer may attach photos of a prescription instead of (or as well as) choosing items. An order with a prescription goes to "awaiting review" until the pharmacy prices it. Some items are age- or prescription-restricted. | Old system: "pharmacy". |
| Fashion / general shopping | Product catalogue with variants (size, colour) expressed as option groups; richer product pages (several images). | Old system: a "commerce" type. |
| Parcels | No catalogue. Customer enters pickup, one or more drop-offs, a package type and size/weight; price is computed from distance and size. The "vendor" here is a delivery operator (WeGoDeliva itself, or a partner courier). | Old system: "parcel"/"package". |

## 1.3 Platform features in scope

- Customer accounts with phone OTP and email/password login; Sign in with Apple and Google (see open questions).
- Vendor onboarding with document upload and admin approval.
- Rider onboarding with documents (ID, licence, vehicle papers) and admin approval.
- Delivery zones drawn as polygons on a map; zone-based or distance-based delivery fees.
- Vendor opening hours, manual open/closed/busy, auto open/close by schedule.
- Cart (single vendor; multi-vendor checkout is an open question), scheduled orders, delivery or self-pickup, rider tip, order notes.
- Coupons (platform-wide, per vendor, per vendor type, per product, or delivery-fee coupons).
- Service fees per vendor (fixed or percentage), tax/VAT.
- Payments: Paystack, Flutterwave (card, bank transfer, USSD), wallet, pay on delivery (cash or transfer to rider).
- Customer wallet with top-up, history, refunds into wallet.
- Vendor and rider earnings, commission, payout requests, payout accounts.
- Rider matching (automatic offers to nearby online riders) and manual assignment by admin/vendor.
- Live tracking of the rider on a map.
- Proof of delivery: handover code (default), optionally photo or signature.
- Ratings and reviews of vendors, riders and products.
- Favourites, search, banners, categories, tags, flash deals (see open questions).
- Referral rewards via share link.
- Push notifications (FCM), SMS OTP, transactional email.
- Admin panel: dashboards, users, vendors, riders, approvals, orders, refunds, payouts, zones and fees, promos, content pages, FAQs, onboarding slides, settings, audit log, reports.
- In-app support: WhatsApp link and an "report a problem with this order" form.

## 1.4 Out of scope — NEEDS OWNER CONFIRMATION

The old system was a generic template that shipped many modules WeGoDeliva does not appear to use. The team proposes to **not build** the following. Nothing on this list will be built until the owner confirms each line (see also [open-questions.md](open-questions.md)).

### Definitely out (template features unrelated to WeGoDeliva's business)

| # | Module | What it did in the old system | Why out |
| --- | --- | --- | --- |
| O1 | Taxi / ride-hailing | Book a car ride; vehicle types with base/distance/time fares; taxi zones; car makes & models; per-currency taxi pricing; driver switch between "taxi" and "delivery" modes; trip fare recalculation and outstanding balances | Not a delivery-marketplace feature. Named out of scope in CLAUDE.md. |
| O2 | Service booking | Book a service (cleaning, salon) by hour/day/month with service options; service vendors | Named out of scope in CLAUDE.md. |
| O3 | Payment gateways other than Paystack and Flutterwave | Stripe, PayPal, Razorpay, Paytm, PayU, Billplz, Abitmedia (iPay88, named in CLAUDE.md, was not present) | Not used in Nigeria; CLAUDE.md names Paystack + Flutterwave only. |
| O4 | SMS gateways not needed | Twilio, Hubtel, Africa's Talking, GatewayAPI, Msg91, Firebase phone auth | Keep **one** Nigerian SMS provider (proposal: Termii) plus one fallback. Owner to confirm which. |
| O5 | Multi-country / multi-currency | Countries → states → cities tables; vendor service areas by country/state/city; currency table; per-order currency | Lagos-only, NGN-only. Service areas are handled by delivery zones (polygons). |
| O6 | Firebase Firestore, Firebase Dynamic Links, Firebase Auth | Rider locations, new-order offers and chat stored in Firestore; share links via Dynamic Links | CLAUDE.md: FCM only. Realtime moves to Supabase Realtime; links move to App Links / Universal Links. |
| O7 | Template self-management | In-app "system upgrade" runner, purchase-code licence check, extension marketplace, database backup/restore page, "troubleshooting" page, HTTP-triggered cron, dev data generators, auto-translator command, server settings page | Replaced by proper CI/CD, hosting, and Laravel scheduler. |
| O8 | QR-code login | Log into the web panel by scanning a QR code with the app | Not needed; adds attack surface. |
| O9 | Fleet management | Fleet companies owning riders and vehicles, fleet reports | Not part of WeGoDeliva's model (riders are independent or vendor-owned). |
| O10 | Digital products | Selling downloadable files with signed download links | WeGoDeliva delivers physical goods. |
| O11 | Remote "custom web view" payment pages for gateways we drop | Hosted pages per gateway | Only Paystack/Flutterwave checkout remains. |

### Probably out — owner decides

| # | Module | What it did | Team's suggestion |
| --- | --- | --- | --- |
| Q1 | Vendor subscription plans | Vendors pay a recurring fee instead of (or as well as) commission; plan limits number of orders | Out for launch; commission only. |
| Q2 | Loyalty points | Points earned per ₦ spent, redeemable into wallet | LATER. |
| Q3 | Wallet-to-wallet transfers between users | Send wallet balance to another user by phone/email | **Out** — moving money between users has CBN/KYC implications. |
| Q4 | Flash sales | Time-limited discounted items on the home screen | LATER (coupons cover launch promos). |
| Q5 | In-app chat (customer↔vendor↔rider) | Firestore-based chat with image messages | Replace with masked phone call / WhatsApp deep link for launch; in-app chat LATER on Supabase Realtime. |
| Q6 | Multiple languages | App and admin strings translated per locale; model translations | English only at launch, built with gen-l10n so Yoruba/Igbo/Hausa/Pidgin can be added. |
| Q7 | Multi-vendor cart (one checkout, several vendors) | Splits into one order per vendor | Out for launch (confusing delivery & fees); single-vendor cart. |
| Q8 | Vendor-owned riders | Vendors creating their own rider accounts who only take that vendor's orders | Keep (useful for restaurants with their own dispatch) — confirm. |
| Q9 | "Offline" payment method | Customer pays by manual bank transfer and an admin confirms | Out — Paystack/Flutterwave bank transfer covers this. |
| Q10 | Social login with Facebook | — | Out; keep Google + Apple (Apple is mandatory on iOS if Google is offered). |
| Q11 | Rider wallet "prepaid" model | Riders had to keep a positive wallet balance to accept cash orders; platform commission deducted from it | Replace with a **cash-on-delivery balance** owed by the rider (CLAUDE.md Phase 5). Confirm. |
| Q12 | Product barcode / SKU scanning | Fields exist | Keep fields; no scanner UI at launch. |
