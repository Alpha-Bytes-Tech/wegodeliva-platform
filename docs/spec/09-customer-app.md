# 9. Customer app — screens

App name **WeGoDeliva**. Android package and iOS bundle ID must stay the same as the live app so users get an update (values recorded in [data-migration.md](data-migration.md) §M.7). Screens marked (old) existed in the old app; (new) are additions from CLAUDE.md Phase 3.

## 9.1 Start and account

| Screen | Purpose / behaviour |
| --- | --- |
| Splash (old) | Load settings (feature flags, support contacts, minimum app version). If the installed version is below the forced minimum → "Update required" screen with store link (old: per-app force-upgrade settings). |
| Onboarding (old) | 3 slides from admin content; shown once. |
| Welcome / location (old) | Ask for location permission; or pick an address / campus manually. Sets the delivery location used for vendor lists. |
| Login (old) | Phone + OTP (primary); email + password; Google; Apple (iOS & Android). |
| OTP entry (old) | 6-digit code, resend timer, auto-read on Android (SMS Retriever). |
| Register (old) | Name, phone (verified by OTP), email (optional or required — open question), password (if email), referral code (pre-filled from link), accept terms. |
| Forgot password (old) | Reset via OTP to phone or link to email. |
| Complete profile (new) | Shown when a social sign-in has no phone yet; phone is required before ordering (old rule). |

## 9.2 Discover

| Screen | Purpose / behaviour |
| --- | --- |
| Home (old, redesigned) | Current address chip (tap to change); search bar; banners carousel; vendor type tiles (Food, Groceries, Pharmacy, Fashion, Parcels); "Open near you"; featured vendors; popular items; reorder strip (last orders); campus mode toggle when inside a campus zone (new). Old home had admin-configurable layout styles — drop; one good layout. |
| Vendor type landing (old: food / grocery / pharmacy / commerce pages) | Categories row, vendors list with filters (open now, rating, delivery fee, distance), sort. Pharmacy landing has an "Upload prescription" entry. Fashion landing shows product-first grid. |
| Categories / subcategories (old) | Browse by category → subcategory → products/vendors. |
| Search (old) | One search across vendors and products, with tabs; recent searches; filters (type, price range, rating, open now). |
| Vendor search (old) | Vendors only, with map/list toggle (SHOULD). |
| Vendor page (old) | Cover, logo, rating & count, open/closed/busy, prep & delivery estimates, delivery fee to my address, minimum order, menu sections, products; info tab (hours, address, phone if allowed); reviews tab. "Closed — schedule for later" when allowed. |
| Vendor reviews (old) | Paginated reviews with rating breakdown. |
| Product details (old; two variants for food vs shop) | Images, description, price (with struck-through original price when on promo), option groups with required/optional rules and max selections, quantity, "Add to cart" with live line price, stock warning, prescription-required badge, reviews. |
| Product reviews (old) | List + summary. |
| Favourites (old) | Saved products (and vendors — new). |
| Flash sale (old) | Only if Q4 kept. |
| Coupon details (old) | Coupon terms; "Use" sets it on the cart. |

## 9.3 Cart and checkout

| Screen | Purpose / behaviour |
| --- | --- |
| Cart (old) | Items grouped under one vendor; edit quantity/options; remove; notes per order; coupon entry with validation; price breakdown from the server quote. Adding an item from a different vendor asks to clear the cart (single-vendor cart, Q7). |
| Checkout — 3 steps (new layout; CLAUDE.md) | **1. Delivery**: delivery or pickup; address (with landmark) or campus building; now or scheduled slot. **2. Payment**: Paystack / Flutterwave / Wallet (shows balance; disabled if short, with "Top up") / Pay on delivery (if allowed). Tip selector (₦0/100/200/500/custom). **3. Review**: full breakdown (subtotal, discount, delivery fee, service fees, tax, tip, total), place order. Handles "price changed" re-confirmation. |
| Payment (old: custom web view) | Gateway checkout (SDK or in-app browser); returns to order status; handles cancel/failed with retry or switch to another method. |
| Order placed (new) | Confirmation with order code and next steps. |

## 9.4 Addresses

| Screen | Purpose / behaviour |
| --- | --- |
| Address list (old) | Saved addresses; set default; edit; delete. |
| New / edit address (old, improved) | Search (places autocomplete) **or pin-drop on map** (new: draggable pin), line 1, **landmark** (new, required), area, contact name/phone, label; for campus: hall/building picker. Shows "We don't deliver here yet" if outside all zones. |

## 9.5 Orders

| Screen | Purpose / behaviour |
| --- | --- |
| Orders list (old) | Tabs: Active / Past. Status chips. Pagination. |
| Order details (old) | Status timeline, items, price breakdown, payment method & status, address, vendor and rider contacts, **handover code** displayed prominently (new), cancel button while cancellable, "Pay now" if awaiting payment, "Report a problem" (new), "Reorder" (new), rate vendor / rider / items after delivery. |
| Live tracking (old: map) | Map with vendor, drop-off and moving rider marker (Supabase Realtime), ETA, rider card with call/WhatsApp. |
| Rate order (old) | Stars + comment for vendor, rider, and each product (product reviews). |
| Pharmacy prescription upload (old) | Pick up to N photos/PDF (size limits from settings), choose pharmacy, note → order goes to `awaiting_review`; later "Review and pay" when priced. |

## 9.6 Parcels

| Screen | Purpose / behaviour |
| --- | --- |
| New parcel (old) | Steps: package type → pickup address + sender contact → drop-off(s) + recipient name/phone (multiple stops if enabled, max stops setting) → size/weight (if required by package type) → when (now/scheduled) → choose operator (list of operators who serve all stops, with price) → payer (sender/recipient) → payment → summary. |
| Parcel order details | As order details, plus per-stop status and codes. |

## 9.7 Wallet, referral, profile

| Screen | Purpose / behaviour |
| --- | --- |
| Wallet (old) | Balance, top-up (amount chips + custom, min/max), transaction history with filters. |
| Wallet transfer (old) | **Removed** if Q3 confirmed out. |
| Loyalty points (old) | Only if Q2 kept. |
| Refer & earn (new screen; old had share in profile) | Code, share link (App Link), how it works, list of referrals and rewards. |
| Notifications inbox (old) | List + details; mark read. |
| Profile (old) | Name, photo, phone, email; links to addresses, wallet, referrals, favourites, support, FAQs, legal pages, language (if Q6), dark mode, logout. |
| Edit profile / change password (old) | Phone change requires OTP. |
| Delete account (old, required by stores) | Explains consequences and blockers (open orders, wallet balance); confirm with OTP. |
| Support (new) | WhatsApp button (number from settings), FAQ, "Report a problem" form (category, order, message, photos) → support ticket; list of my tickets with status. |
| Chat (old) | Replaced at launch by call/WhatsApp (Q5). |
| Legal / content pages (old) | Terms, privacy, about. |

## 9.8 Cross-cutting

- Offline/poor network: cached home and last vendor pages; clear retry states.
- Deep links (App Links / Universal Links): `/r/{code}` referral, `/v/{slug}` vendor, `/p/{id}` product, `/o/{code}` order (requires login).
- Accessibility per CLAUDE.md; dark mode; dynamic text.
- Analytics events (SHOULD): view vendor, add to cart, begin checkout, purchase — no personal data.
