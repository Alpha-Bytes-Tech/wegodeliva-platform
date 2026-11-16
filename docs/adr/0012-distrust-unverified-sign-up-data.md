# 0012. Distrust everything an unverified sign-up chose

Date: 2026-11-16 · Status: Accepted

## Context
Anyone can call `POST /auth/register` with any email address, plus a phone number and password of their choosing. Until 2026-11-16, when the real owner of that address later signed in with an email code (or Google/Apple, or a password-reset code), we only set `email_verified_at`. The person who registered first kept a working password, so they could sign in to the owner's account with `/auth/login` ("account pre-hijacking"). The same happened when a social sign-in linked to an unverified account.

## Decision
- The first time an account's email is proven — email sign-in code, password-reset code, or a Google/Apple ID token whose email the provider has verified — `EmailVerification::markVerified()` runs one transaction (with a row lock) that:
  1. sets `email_verified_at`;
  2. sets `password` to null (the reset-code flow then stores the new password the owner just chose);
  3. deletes every existing Sanctum token, so nothing issued before the proof keeps working;
  4. sets `users.phone_confirmation_required = true`.
- Later verifications change nothing; verified accounts link to Google/Apple as before.
- The user resource exposes `phone_confirmation_required` and `has_password`. While the flag is set the apps ask the user to re-enter their phone number and send it to `POST /api/v1/auth/phone/confirm` (signed-in, one-time: 409 `phone_already_confirmed` afterwards), which saves it and clears the flag. Users without a password can set one with the existing password-reset code flow; OTP and social sign-in keep working without one.
- We chose re-entering the phone over silently keeping it because the phone is used for delivery contact and pay-on-delivery fraud checks, and was chosen by an unproven party.

## Consequences
- Honest users who register with a password lose it when they first verify their email and must set it again (reset-code flow) if they want password sign-in. The customer app should offer "set a password" right after verification so this is one extra step, not a surprise.
- Name and referral code chosen at sign-up stay; the owner can edit their name later (profile, Phase 3).
- Until the apps implement the phone-confirmation screen (Phase 3), the backend only reports the flag; nothing is blocked server-side.
