# 11. Rider app — screens

App name **WeGoDeliva Rider** (keep the live package/bundle ID). The old rider app also contained a taxi mode, vehicle registration with car make/model, and a taxi trip screen — all out of scope (O1).

| Screen | Purpose / behaviour |
| --- | --- |
| Splash / onboarding / force-update (old) | Rider audience. |
| Permissions (old) | Explains and requests location ("all the time" on Android for background tracking, with the Play-required prominent disclosure), notifications, battery-optimisation exemption (SHOULD). Blocks going online until granted. |
| Login (old) | Phone OTP or email + password; rider role required. |
| Register (old) | Name, phone, email, password; rider type (independent by default; WeGoDeliva-managed set by admin; vendor-owned via invite); vehicle kind (bicycle / motorbike / car / on foot), plate number, colour; documents (ID, licence, vehicle papers, guarantor — count from settings), selfie (SHOULD). → Pending approval. |
| Pending approval / document requests (old) | Status of documents, upload what admin requests. |
| **Home** (old) | Big Online/Offline switch; today's earnings and jobs; COD cash balance and limit; current job card; map with own position; low-data mode toggle (new). |
| **Job offer** (old: new-order alert) | Full-screen ringing offer with countdown: pickup area & distance, drop-off area, trip distance, earning, cash to collect, package type. Accept / Decline. |
| Active job (old: order details) | Step-by-step (new, CLAUDE.md): Go to pickup → Arrived → Picked up → Go to drop-off → Hand over. Navigation buttons (Google Maps / Waze), call vendor/customer (masked), customer note and landmark, items summary, cash to collect. |
| Handover (old: code / photo / signature verification) | Enter the handover code — required on every delivery; no photo/signature fallback (if the customer can't give it, call support). Pay-on-delivery: confirm "Cash collected" / "Transfer received". |
| Failed delivery (new) | Reason, photo, call-attempt check. |
| Parcel stops | List of stops with per-stop verification codes when required. |
| Jobs history (old: orders / assigned orders) | Active and completed jobs; details. |
| Earnings (old: finance / wallet) | Gross earnings, commission due (3% of delivery fees, settled at withdrawal), available to withdraw, per-job ledger, tips, daily/weekly summary, COD balance and **remit** instructions (transfer to WeGoDeliva account with reference; admin confirms). |
| Payout accounts (old) | Add/edit verified bank account. |
| Payouts (old: in finance) | Request payout (net of COD owed), history. |
| Vehicle (old: vehicles list / new vehicle) | Simplified to one vehicle profile; changes need admin re-approval. |
| Profile (old) | Photo, name, phone, rating, documents, legal pages, support, logout. |
| Edit profile / change password / delete account (old) | |
| Support (old) | WhatsApp, FAQ, report a problem, emergency contact (old setting). |
| Notifications inbox (old) | |
| Chat (in scope at launch) | Per-job chat with the customer (and vendor — SHOULD); support chat. Quick replies ("I'm outside", "On my way"). |

Background behaviour:
- Foreground service (type `location`) with an "You are online" notification while online.
- Location sending as in [07](07-dispatch-and-delivery.md) §7.4; batched retry when offline; low-data mode.
- Offers must arrive and ring with the app in background or killed (FCM high-priority data message + full-screen intent on Android; time-sensitive notification on iOS).
