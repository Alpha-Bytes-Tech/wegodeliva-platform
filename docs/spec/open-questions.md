# Open questions for the owner

Items 1–27 were answered on 2026-11-01. The answers have been applied to the spec; this file keeps them as a record. The **remaining questions** at the end still need answers. ★ = blocks Phase 1.

## Answered 2026-11-01

| # | Question | Answer | Applied in |
| --- | --- | --- | --- |
| 1 | Out-of-scope list O1–O11 | Confirmed, later amended: services moved into scope | [01](01-scope.md) §1.5 |
| 2 | Optional modules Q1–Q12 | Team suggestions accepted; later amended: in-app chat moved to launch (Q5) | [01](01-scope.md) §1.5 |
| 3 | Live vendors/customers | All are test/demo users | [data-migration](data-migration.md) §M.0 |
| 4 | Verticals at launch | Fashion and all the others, including services | [01](01-scope.md) §1.3 |
| 5 | Campuses | UNILAG, LASU, YABATECH, LASUSTECH, UI, FUNAAB, UNIBEN, OOU, OAU | [01](01-scope.md) §1.2 |
| 6 | Commission | 5% vendor; 3% on delivery fee | [05](05-pricing-fees-commission.md) §5.8 |
| 7 | VAT | Prices exclude VAT; no VAT on delivery or service fees; WeGoDeliva is VAT-registered | [05](05-pricing-fees-commission.md) §5.6 |
| 8 | Coupon funding | Vendor | [05](05-pricing-fees-commission.md) §5.4 |
| 9 | Parcel extra stops | Per additional stop | [05](05-pricing-fees-commission.md) §5.3 |
| 10 | Refund fault | Team to advise on best practice | [06](06-payments-wallet-payouts.md) §6.6 |
| 11 | Wallet limits | CBN tiers; no withdrawal to bank | [06](06-payments-wallet-payouts.md) §6.4 |
| 12 | Pay on delivery | All vendors and zones; max ₦20,000 | [06](06-payments-wallet-payouts.md) §6.5 |
| 13 | Rider cash limit | ₦20,000; commission deducted at withdrawal | [05](05-pricing-fees-commission.md) §5.8, [06](06-payments-wallet-payouts.md) §6.5 |
| 14 | Payouts | On request via Paystack/Flutterwave Transfers | [06](06-payments-wallet-payouts.md) §6.7 |
| 15 | Referral | 5% of the new user's first purchase, max ₦5,000, to the referrer's wallet | [06](06-payments-wallet-payouts.md) §6.8 |
| 16–17 | Timeouts and radius | As proposed | [04](04-order-lifecycle.md), [07](07-dispatch-and-delivery.md) |
| 18 | Proof of delivery | Handover code on every delivery | [07](07-dispatch-and-delivery.md) §7.3 |
| 19 | Rider types | All three | [07](07-dispatch-and-delivery.md) §7.6 |
| 20 | Support | WhatsApp number later; in-app chat | [01](01-scope.md), [03](03-entities.md) §3.11 |
| 21 | SMS provider | Not set up yet | see R2 |
| 22 | Sign-up | Email and phone both required | [03](03-entities.md), [09](09-customer-app.md) |
| 23 | Google + Apple | Yes, at launch | [01](01-scope.md) §1.4 |
| 24 | Firebase | Keep the existing project; owner has owner access | [08](08-notifications.md) |
| 25 | Keystores / signing | No | [data-migration](data-migration.md) §M.7; see R1 |
| 26 | Production | cPanel | [data-migration](data-migration.md) §M.0 |
| 27 | Loyalty points → wallet | Yes (nothing to convert: test users) | [data-migration](data-migration.md) §M.0 |

## Interpretations the team made — please correct if wrong

- **"The others, including services"** = all six vendor types (food, groceries, pharmacy, fashion, parcels, services). **Taxi stays out.**
- **"3% commission on delivery fee"** = WeGoDeliva keeps 3% of the delivery fee; the rider keeps 97% plus all tips.
- **"Commission deducted at withdrawal"** applies to vendors and riders: earnings are credited gross, and commission (plus any cash a rider owes) is settled when they withdraw.
- **Referral "purchase"** = goods/services value after discount, excluding delivery, fees, VAT and tip. The reward is paid when the order is delivered or the booking is completed.
- **Campuses outside Lagos** (UI, FUNAAB, OOU, OAU, UNIBEN) make the platform multi-city within Nigeria. The product description changes from "for Lagos" to "for Nigeria, starting with Lagos and campuses".

## Remaining questions

- **R1 ★ App signing.** In Play Console, is Play App Signing enabled for the three apps? Who owns the Apple Developer account? This decides whether we keep the old package IDs or publish new apps ([data-migration](data-migration.md) §M.7).
- **R2 ★ SMS provider.** OK to open a Termii account (with DND route) for OTP? It costs money, so your approval is needed. Until then dev/staging log OTPs instead of sending them.
- **R3 Accountant.** (a) Does WeGoDeliva charge and remit VAT on vendors' goods, or does each vendor? (b) Which categories are VAT-exempt (basic food, pharmaceuticals)? (c) Is VAT due on WeGoDeliva's commission?
- **R4 Legal.** Can WeGoDeliva hold a closed-loop customer wallet without a CBN licence, and what are the current CBN tier limits ([06](06-payments-wallet-payouts.md) §6.4)?
- **R5 Riders.** Are the riders on the old system also test accounts?
- **R6 Services list.** Which service categories at launch (laundry, cleaning, hair & beauty, repairs, tutoring…)?
- **R7 Cancellation fee** after the vendor accepts a food order: is 50% of the item value OK?
- **R8 Campus boundaries.** Can ops share a map pin or rough boundary for each campus? Can be done later in the admin panel.
- **R9 Support WhatsApp numbers** — when available.
