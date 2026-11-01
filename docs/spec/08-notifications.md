# 8. Notifications

Channels: **push** (FCM, per device token, per app), **in-app inbox** (stored notifications, read/unread), **email** (OTP codes, receipts, account, admin alerts), **SMS** (none at launch; LATER), **realtime** (Supabase Realtime for live screens). Message texts are editable by admins (template per event, with placeholders like `{order_code}`, `{vendor_name}`) — the old system had per-status editable messages for customers and for vendors.

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
- Quiet failure: if push fails for a vendor on a new order, email the vendor and alert ops after 2 minutes (SHOULD). SMS fallback LATER.
- **OTP by email** (owner decision 2026-11-01). Send through a transactional email provider on a high-priority stream, separate from marketing mail, with SPF/DKIM/DMARC set up so codes don't land in spam. Target delivery under 30 s, monitored. The app shows "Check your spam folder" after 60 s.
- Email provider (owner to choose; several have free tiers): e.g. Postmark, Amazon SES, Resend, Mailgun; sender `no-reply@wegodeliva.com`, SPF/DKIM/DMARC set.
- Users can mute marketing broadcasts but not transactional order notifications.

## 8.3 Additions (2026-11-01)

| Event | Customer | Vendor / provider | Rider | Admin |
| --- | --- | --- | --- | --- |
| New chat message | push (unless the chat is open) | push | push | support inbox realtime |
| Service booking requested | push: "Request sent" | **loud push** | | |
| Booking confirmed / declined | push | | | |
| Booking reminder (24 h, 1 h before) | push | push | | |
| Booking started / completed | push (confirm or report a problem) | | | |
| Booking disputed | push | push | | alert |
| Referral reward credited (5%, max ₦5,000) | push to referrer | | | |
| KYC tier upgraded / verification failed | push | | | |
| Commission settled at withdrawal | | statement email | statement email | |

The support WhatsApp number is added to settings when the owner provides it; until then support runs through in-app chat.
