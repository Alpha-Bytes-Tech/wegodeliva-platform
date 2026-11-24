# Phase 3 plan — customer app

Draft for the owner's approval (2026-11-24). Each milestone is one PR, merged only on green CI.
The app is built from `docs/spec/09-customer-app.md` on the Phase 1 shell (`mobile/apps/customer`,
packages `core`, `ui`, `auth`). Where a screen needs an API that Phase 2 did not build, the
milestone adds it to the backend first, with feature tests.

| # | Milestone | What it covers |
| --- | --- | --- |
| 3.1 | **App backend gaps** | Public app settings (feature flags, support WhatsApp number, minimum app version, legal pages, onboarding slides, banners); profile edit, change password, change email by OTP, delete account by OTP; signed upload URLs to private Supabase Storage (avatars, problem-report photos, prescriptions); support tickets ("Report a problem"); reorder (rebuild a cart from a past order). |
| 3.2 | **App foundations** | Bottom-navigation shell (Home, Search, Orders, Wallet, Profile); go_router routes and deep links (`/r/{code}`, `/v/{slug}`, `/p/{id}`, `/o/{code}`) with App Links; typed API models; loading, empty, error and retry states; offline cache of home and recent stores; splash with settings and "update required"; location permission and the current-address chip; dark mode and text scaling. CI builds a staging APK on every PR so the owner can install it. |
| 3.3 | **Discover** | Home (address chip, search, banners, vendor-type tiles, open near you, featured, reorder strip, campus toggle); vendor-type landings with filters and sort; categories; search (stores and products, recent searches); store page (menu, info, reviews, "schedule for later"); product details (options with rules, live price, stock, reviews); favourites. |
| 3.4 | **Addresses, cart and checkout** | Addresses with pin-drop map and required landmark, campus building, "we don't deliver here"; single-store cart with options, notes and coupon; 3-step checkout (delivery → payment and tip → review) priced by the server quote, "price changed" re-confirmation; payment by gateway checkout and verification; order placed. Unit and widget tests for cart and checkout. |
| 3.5 | **Orders and tracking** | Orders list (active / past / bookings); order details with timeline, handover code, cancel, pay now, reorder, report a problem; live status through Supabase Realtime (Laravel-minted JWTs, ADR 0004) with push as fallback; tracking map with store and drop-off (rider marker once riders send locations in Phase 5); rating store, rider and items. Widget tests for tracking. |
| 3.6 | **Wallet, referrals, profile, inbox, support** | Wallet balance, top-up and history with tier limits; refer and earn with share link; notifications inbox; profile, edit, password, delete account; support (WhatsApp button, FAQ, report a problem, my tickets); legal pages. |
| 3.7 | **Parcels and services** | New-parcel flow (package, stops, size, when, operator list, payer, payment) and parcel details with stop codes; services landing, provider page, service details, booking with free slots, booking details (start code, cancel with fee preview, confirm or report a problem). |
| 3.8 | **Chat** | Backend: conversations and messages in Postgres, writes through Laravel, broadcast on Supabase Realtime; customer ↔ store, customer ↔ rider (from assignment to 24 h after delivery) and customer ↔ support; text and images; read receipts; push for new messages. App: chat screens from order, booking and support. |
| 3.9 | **Pharmacy prescriptions** | Backend: prescription upload puts the order in `awaiting_review`; the pharmacy prices it and the customer reviews and pays (the pharmacy's screen follows in Phase 4). App: upload flow and "review and pay". |

**Done when** the customer can find a store, order, pay, track and rate; send a parcel; book a
service; chat; and manage wallet and profile, on the staging API, with `flutter analyze` clean
and unit and widget tests for cart, checkout and tracking.

## Owner decisions (2026-11-24)

1. **Maps**: Google Maps (pin-drop, places search, tracking map). The owner creates the keys in Google Cloud with billing on, restricted to the app's package name and signing SHA-1 (Android) and bundle ID (iOS); the Places key is restricted to the backend if search is proxied. Keys are never committed: builds read them from CI secrets.
2. **Card, transfer and USSD payments**: the gateway's hosted checkout in a secure in-app browser, then server verification (the Phase 2 API). No gateway SDKs.
3. **Wallet KYC**: the wallet shows the current tier and limits; BVN/NIN verification for Tier 2 waits until the owner picks a provider before launch.
4. **Testing builds**: CI builds a staging Android APK on every PR, downloadable from the PR's checks.

## Notes from 3.1 (app backend gaps)

- **Start-up settings:** `GET /app/settings?app=customer|vendor|rider` (public) returns:
  - minimum and latest app version and store links;
  - feature flags (parcels, multi-stop, service bookings, referrals, wallet top-ups, campus mode, Google and Apple sign-in);
  - which payment methods are on;
  - limits (pay-on-delivery maximum, maximum tip, parcel stops, ticket photos);
  - support contacts and ticket categories;
  - published pages;
  - onboarding slides (customer app only).

  Versions and contacts come from environment variables (`MIN_VERSION_*`, `LATEST_VERSION_*`, `PLAY_STORE_URL`, `APP_STORE_URL`, `SUPPORT_WHATSAPP`, `SUPPORT_EMAIL`, `SUPPORT_PHONE`).
- **Content:** pages (`GET /app/pages/{slug}`, Markdown) and home banners (`GET /app/banners?vendor_type=`), active within their dates. Admins manage them with `GET /admin/pages`, `PUT /admin/pages/{slug}`, `GET/POST/PATCH /admin/banners` (audited). Banner images live under `banners/` in storage.
- **Uploads:** `POST /uploads` (`kind`: avatar, support_photo or prescription; `content_type`) returns a one-use signed upload URL in the private Supabase bucket, under `{folder}/{user uuid}/…`. Files are read through short-lived signed URLs, cached for half their life. Without `SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY`, uploads answer 503 and image URLs are null; nothing else breaks.
- **Account (all apps):**
  - `PATCH /auth/me`: name, avatar, phone. A new phone needs the password if the account has one.
  - `POST /auth/password`: change, or set one for an account without a password. Signs out the user's other sessions.
  - `POST /auth/email/change` then `/auth/email/confirm`: the code goes to the new address, and the email changes only once it is entered.
  - `POST /auth/account/delete-code` then `DELETE /auth/account`.
- **Deleting an account:**
  - It is blocked by open orders or jobs, a non-zero wallet, rider earnings or cash owed, or owning a store; the response says which.
  - Personal data (name, email, phone, password, avatar, addresses, favourites, devices, sign-in links) is removed and the account closed. Orders, payments and ledgers stay, linked to an anonymous user. The email address and phone number can be used again.
- **Support tickets:**
  - Customers report a problem with `POST /me/support-tickets` (category, optional order, message, up to 5 photos), and see their tickets with `GET /me/support-tickets[/{id}]`.
  - Support lists them with `GET /admin/support-tickets?status=` and updates them with `PATCH /admin/support-tickets/{id}` (status and resolution; audited). The customer gets a notification when the status changes.
  - Conversation with support comes with chat (3.8).
- **Reorder:** `POST /me/orders/{code}/reorder` returns the items still on sale with options still offered, and lists what could not be added and why. The app loads them into the cart and quotes again at today's prices. Parcels and bookings cannot be reordered.
- **Owner actions:**
  - In Supabase, create a **private** Storage bucket named `uploads`.
  - In Laravel Cloud, add `SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY` (Supabase → Project Settings → API; the service-role key is a secret, so put it only in Laravel Cloud).
  - Set `SUPPORT_WHATSAPP` (and optionally `SUPPORT_PHONE`).
