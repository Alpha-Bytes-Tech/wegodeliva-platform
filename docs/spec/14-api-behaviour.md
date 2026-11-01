# 14. API behaviour

## 14.1 Conventions (MUST)

- Base path `/api/v1`. JSON only. HTTPS only.
- Auth: `Authorization: Bearer <sanctum token>` for apps. Each token is bound to the app it was issued for (customer / vendor / rider) via token abilities; a rider token cannot call vendor endpoints.
- Public ids: UUIDs (or order codes) in URLs, never sequential ids for user-owned resources.
- Pagination: cursor-based for feeds and lists (`?cursor=`), `per_page` max 50.
- Money in responses: `{ "amount_kobo": 150000, "formatted": "₦1,500.00" }`.
- Error format (all endpoints):
  ```json
  { "error": { "code": "order_not_cancellable", "message": "This order can no longer be cancelled.", "fields": { } } }
  ```
  HTTP 400 validation (`fields` filled), 401 unauthenticated, 403 forbidden, 404 not found (also used when the resource exists but isn't yours), 409 conflict (state changed / already taken), 422 business rule, 429 rate limited, 500 generic message only (details in logs). Messages are translatable.
- Idempotency: `Idempotency-Key` header required on place-order, top-up, payout request, refund; replays return the original response.
- Rate limits: login/OTP/password reset per email address and IP; checkout and wallet per user; search per IP.
- OpenAPI document generated from code and published for the apps.
- App version header `X-App-Version`; server can answer 426 "update required".

## 14.2 Endpoint groups (behaviour, not final routes)

| Group | Behaviour |
| --- | --- |
| Bootstrap | Public app config (feature flags, support contacts, min versions), onboarding slides, FAQs, content pages, cancellation reasons. |
| Auth | Send OTP by email, verify OTP (login or sign-up), email login, register, social login (Google/Apple id-token verification server-side), password reset, logout, refresh profile, delete account. |
| Profile & devices | Get/update profile, change email (OTP to the new address), change phone (password re-entry), change password, register/unregister FCM token. |
| Addresses | CRUD own addresses; zone lookup for a point ("do you deliver here?"). |
| Catalogue | Vendor types, categories, subcategories, tags, banners; vendors near a point (filters, sort, open state, delivery fee estimate); vendor details; vendor menu; product details; search (vendors + products); reviews. |
| Favourites | Add/remove/list. |
| Cart quote | Quote for a vendor cart (items, options, fulfilment, address, schedule, coupon, tip) → full breakdown + `quote_id`. Coupon validation endpoint. |
| Orders (customer) | Place, list own, details, cancel, pay/retry payment, verify payment, rate, report problem, reorder (returns a new quote), tracking token (Supabase JWT for the order channel). |
| Parcels | Package types; quote (stops, package, size, schedule) → operators with prices; place; per-stop status. |
| Payments | Gateway initialisation for order / top-up; verify; webhooks (`/webhooks/paystack`, `/webhooks/flutterwave`). |
| Wallet | Balance, transactions, top-up. |
| Referrals | My code/link, my referrals. |
| Vendor | Auth'd vendor context (switch vendor), orders inbox (filters), accept (prep time) / reject (reason) / ready / handover, pharmacy repricing, store status, hours, products CRUD, stock, menus/options CRUD, parcel rates, earnings ledger, payout accounts, payout requests, staff, own riders, reviews, documents. |
| Rider | Go online/offline, location updates (batch), offers (accept/decline), active job actions (arrived, picked up, delivered with code, failed), jobs history, earnings, COD balance/remittances, payout accounts, payout requests, documents, vehicle. |
| Realtime auth | Mint short-lived Supabase JWTs scoped to: customer → own order channels; vendor → own vendor channel; rider → own rider channel + assigned order channels. |
| Admin | Behind the admin web app; same API with admin abilities, or a separate `/api/v1/admin` group. |
| Health | `/health` (liveness), `/health/ready` (DB, queue, cache, gateway reachability). |

## 14.3 Behaviours to keep from the old API
- Settings bootstrap call on app start.
- "Do you deliver here" check before showing vendors.
- Delivery fee summary before checkout.
- Summary/quote before placing an order, protected against tampering (old used an encrypted token; new uses a server-stored quote bound to the user and re-validated at placement).

## 14.4 Behaviours deliberately changed
See [legacy-notes.md](legacy-notes.md) for the defects behind each: order listing by arbitrary vendor/driver id, order details without ownership check, client-settable payment status, mass assignment on update, role chosen at sign-up, wallet transfers without re-authentication, unauthenticated webhooks, totals trusted from the client on parcel orders.

## 14.5 Additions (2026-11-01)

| Group | Behaviour |
| --- | --- |
| Services | Service categories; providers near a point; service details; available slots for a date range; booking quote; place booking; booking actions (customer: cancel, confirm completion, dispute; provider: accept/decline, start with code, complete). |
| Chat | List my conversations; open/create the conversation for an order (participants derived from the order — clients can't choose arbitrary participants); send message (text/image, rate-limited); mark read; realtime token for `conversation:{uuid}`; support conversation create. |
| KYC | Current tier and limits; start BVN/NIN verification (server calls the KYC provider; the full number is never returned or logged). |
| Cities & campuses | List active cities and campus zones; "which city/campus am I in". |
