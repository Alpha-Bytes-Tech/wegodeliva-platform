# Open questions for the owner

Answer inline (or in chat) and the team will update the spec. Items marked ★ block Phase 1–2 design; others can wait until the relevant phase.

## Scope
1. ★ Confirm the **definitely-out** list O1–O11 in [01-scope.md](01-scope.md) §1.4.
2. ★ Decide each **probably-out** item Q1–Q12 (subscriptions, loyalty, wallet transfers, flash sales, chat, languages, multi-vendor cart, vendor-owned riders, offline payment, Facebook login, rider prepaid wallet, barcode).
3. Which vendor types are live today and roughly how many vendors/riders/customers are active? (Helps size the migration and the launch.)
4. Is "fashion" really in scope at launch, or food / groceries / pharmacy / parcels first?
5. Which campuses are first? We need their boundaries to draw campus zones.

## Money
6. ★ Default vendor commission % and rider share % of the delivery fee. Do any vendors have special rates today?
7. ★ VAT: are prices VAT-inclusive? Is VAT charged on delivery and service fees? Is WeGoDeliva VAT-registered? (Confirm with your accountant.)
8. ★ Who pays for coupons by default — WeGoDeliva or the vendor?
9. Parcel extra-stop fee: per additional stop (proposed) or per stop once there are more than two (old)?
10. Refund cost: when an order is cancelled for vendor fault / rider fault / customer fault, who loses what?
11. Wallet top-up maximum and any KYC limits (CBN tiers)? Can customers withdraw wallet balance to a bank account? (Proposed: no.)
12. Pay on delivery: allowed for all vendors and zones? Maximum order value for pay on delivery?
13. Rider cash limit before they must remit (proposed ₦20,000)? How do riders remit today?
14. Payouts: on request, or automatic weekly? Manual bank transfer by admin, or via Paystack/Flutterwave Transfers (needs a funded balance)?
15. Referral reward amounts (referrer / new user) and conditions.

## Operations
16. Vendor acceptance timeout (proposed 10 min) and what happens next (cancel + refund).
17. Rider offer timeout (proposed 30 s) and search radius (proposed 3 km; smaller on campus).
18. Proof of delivery: handover code for every order? Allow photo fallback?
19. Are riders WeGoDeliva-managed, independent, or vendor-owned (or all three)?
20. Support channels: WhatsApp number(s) for customers, vendors, riders; support hours.

## Accounts and tech
21. ★ SMS provider for OTP today (Termii? other?) and do you have an account with a DND route?
22. Email: is email required at customer sign-up, or phone only?
23. Google and Apple sign-in at launch?
24. Firebase: keep the existing Firebase project (for FCM, so existing tokens keep working) or create a new one? Who has owner access to it, the Play Console, App Store Connect and the `wegodeliva.com` DNS?
25. ★ Do you have the **upload keystores' passwords** (or is Play App Signing enabled) and the Apple distribution certificate access? Without these, the new apps cannot update the existing ones.
26. Where is the current production server and database, and who can produce a MySQL dump for the Phase 7 rehearsal?
27. Loyalty points: if dropped, should existing points be converted to wallet credit?
