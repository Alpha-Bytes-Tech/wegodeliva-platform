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

## Answered 2026-11-01 (second round)

| # | Answer | Applied in |
| --- | --- | --- |
| R1 | Play App Signing is on for all three apps; Alpha Bytes owns the Apple Developer account | [data-migration](data-migration.md) §M.7: keep the package IDs; request upload key resets |
| R2 | OK to open Termii, starting on the free tier | Owner creates the account (the team can't create accounts or handle passwords), then puts the API key in the staging secret store |
| R3 | (a) Each vendor charges and remits VAT; (b) team to research exemptions; (c) VAT on commission: yes | [05](05-pricing-fees-commission.md) §5.6, §5.8 |
| R4 | Team to research and advise | [06](06-payments-wallet-payouts.md) §6.4.1: recommends launching without cash top-ups |
| R5 | Riders are test accounts too | [data-migration](data-migration.md) §M.0 |
| R6 | Use the services list from the existing app | The list is **not in the old code**. It lives in the live database. Needs an export (see S1) |
| R7 | 50% cancellation fee: yes | [13](13-settings.md) |
| R8 | Campus boundaries later | Phase 6 admin panel |

## Remaining questions

- ~~S1 Services list~~ **Answered 2026-11-01:** 14 categories, see [01](01-scope.md) §1.3.1.
- ~~S2 Wallet decision~~ **Answered 2026-11-01:** cash top-ups stay in; money is held at Paystack/Flutterwave and the app only shows balances; both gateways can hold earnings until withdrawal. See [06](06-payments-wallet-payouts.md) §6.4.1.
- **S3 Before launch (not before Phase 1):** written confirmation from the Paystack and Flutterwave account managers that their terms allow customer stored balances and held earnings; a short fintech-lawyer opinion on the wallet. Accountant: the default VAT table, VAT on platform service fees, and the CBN tier figures. Not needed before Phase 1.
- ~~S4 Termii~~ **Answered 2026-11-01:** OTP goes by email instead of SMS. New item: choose a transactional email provider and set up SPF/DKIM/DMARC on wegodeliva.com (owner creates the account).
- **S5 Support WhatsApp numbers**, when available.
