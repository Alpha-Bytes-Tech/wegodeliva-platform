# 0008. One-time codes by email

Date: 2026-11-01 · Status: Accepted

## Context
Owner decision: send OTPs by email instead of SMS at launch. Email and phone are both required at sign-up.

## Decision
- OTPs go by email: 6 digits, stored hashed, valid 10 minutes, max 5 attempts, resend after 60 s, rate-limited per email address and per IP.
- Sent through a transactional email provider on a dedicated stream, with SPF/DKIM/DMARC on wegodeliva.com. In local development, Mailpit catches the emails.
- Phone numbers are format-checked but not verified at launch. `otp_challenges.channel` leaves room for SMS or WhatsApp later.

## Consequences
- No SMS cost at launch, but email deliverability becomes critical and is monitored.
- Fraud controls can't rely on verified phones, which is one reason pay on delivery is capped at ₦20,000.
