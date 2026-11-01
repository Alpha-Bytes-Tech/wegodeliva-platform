# 8. Notifications

Channels: **push** (FCM, per device token, per app), **in-app inbox** (stored notifications, read/unread), **SMS** (OTP and a few critical messages), **email** (receipts, account, admin alerts), **realtime** (Supabase Realtime for live screens). Message texts are editable by admins (template per event, with placeholders like `{order_code}`, `{vendor_name}`) — the old system had per-status editable messages for customers and for vendors.

## 8.1 Events

| Event | Customer | Vendor | Rider | Admin |
| --- | --- | --- | --- | --- |
| Account created | welcome email (SHOULD) | | | email: new vendor / rider awaiting approval |
| Vendor / rider approved or rejected | | push + email | push + email | |
| Document requested by admin | | push + email | push + email | |
| Order placed (paid / confirmed) | push: "Order received" | **loud push + realtime ring** in vendor app until acknowledged | | dashboard realtime |
| Prescription priced by pharmacy | push: "Review and pay" | | | |
| Order accepted (with prep time) | push: "Being prepared, ready in ~X min" | | | |
| Vendor rejected / order cancelled | push + inbox with reason and refund info | push if cancelled by customer/admin | push if assigned | |
| Vendor acceptance timeout warning | | push reminder | | alert |
| Rider job offer | | | **high-priority data push + ring**, countdown | |
| Offer expired / job taken | | | silent update | |
| Rider assigned | push: rider name & ETA | push: rider coming | | |
| Order ready | push (self-pickup only) | | push: "Ready for pickup" | |
| Picked up | push: "On the way" + tracking link | push | | |
| Rider nearby (~300 m) | push (SHOULD) | | | |
| Delivered | push + email receipt; rating prompt | push | push: earning credited | |
| Delivery failed | push + inbox | push | | alert |
| No rider found (escalation) | push: "Still finding a rider" | push | | alert |
| Payment succeeded / failed | push | | | |
| Refund issued | push + inbox + email | | | |
| Wallet top-up credited | push | | | |
| Referral reward credited | push | | | |
| Payout approved / paid / failed | | push + email | push + email | |
| Admin broadcast | push to audience (all / customers / vendors / riders / zone) | same | same | |
| Support ticket update | push | push | push | new ticket alert |

## 8.2 Rules

- Notification topics are per user (send to all their device tokens), never shared topics that anyone can subscribe to. The old system used guessable topic names per user, which allowed spying on another user's notifications (D11).
- Payloads carry only ids and short text — no addresses or phone numbers.
- Invalid tokens reported by FCM are removed.
- Vendor "new order" sound repeats until the order is opened; works with the app in background (Android notification channel with custom sound; iOS critical alert not available → time-sensitive notification).
- Quiet failure: if push fails for a vendor on a new order, send SMS after 2 minutes (SHOULD).
- OTP SMS via the chosen Nigerian provider (Termii proposed) with DND-route support; fallback provider configurable.
- Email via a transactional provider (e.g. Postmark / Amazon SES / Mailgun — owner to choose); sender `no-reply@wegodeliva.com`, SPF/DKIM/DMARC set.
- Users can mute marketing broadcasts but not transactional order notifications.
