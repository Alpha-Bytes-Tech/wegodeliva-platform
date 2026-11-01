# 2. Roles and permissions

## 2.1 Roles

A person has one user account and one or more roles. Roles are assigned by the system or an admin — **never chosen by the client at sign-up** (the old system let the sign-up request name a role; see legacy-notes D1).

| Role | Who | Created how |
| --- | --- | --- |
| `customer` | Anyone ordering | Self sign-up in the customer app (email OTP; phone and email both required) |
| `vendor_owner` | Person who registered the vendor | Self sign-up in the vendor app/portal → inactive until admin approves the vendor |
| `vendor_staff` | Cashier/kitchen staff for a vendor | Invited by the vendor owner or an admin |
| `rider` | Delivery rider | Self sign-up in the rider app → inactive until admin approves documents |
| `admin` | WeGoDeliva operations staff | Created by a super admin |
| `super_admin` | Owner / tech lead | Seeded; can manage admins and settings |
| `zone_admin` (SHOULD) | Ops staff limited to some cities or zones (e.g. one campus) | Created by super admin. Replaces the old "city admin" role. |

A vendor can have several staff; a staff member can belong to several vendors and switch between them in the vendor app (old behaviour: "my vendors" + "switch vendor").

## 2.2 Permission matrix (MUST)

"Own" means the record belongs to the caller (customer's own order; vendor's own products/orders; rider's own assigned orders). All checks are enforced server-side by policies.

| Resource | Customer | Vendor owner / staff | Rider | Zone admin | Admin / super admin |
| --- | --- | --- | --- | --- | --- |
| Own profile, addresses, favourites | read/write | read/write | read/write | — | read/write all |
| Vendors (public info), products, categories | read active | write own vendor | read | read zone | write all |
| Orders | create; read own; cancel own while cancellable; rate own | read own vendor's; accept/reject; set preparing/ready; assign own riders | read offered + assigned; accept/decline offer; mark picked up / delivered / failed | read/write zone | read/write all; force status; reassign |
| Payments | pay own order | read own vendor's | — | read zone | read all; mark refund |
| Wallet | read own; top up | — | read own COD balance | — | read all; manual credit/debit (with reason, audited) |
| Earnings / payouts | — | read own vendor; request payout (owner only) | read own; request payout | — | approve/reject/mark paid |
| Coupons | apply | create for own vendor (SHOULD) | — | — | write all |
| Zones, fees, settings | — | — | — | read | write (super admin for settings) |
| Users, roles | — | invite own staff | — | read zone riders/vendors | write (super admin for admin roles) |
| Audit log | — | — | — | — | read |

## 2.3 Account states

- `active` — can log in and use the app.
- `pending_approval` — vendors/riders after sign-up; can log in to complete documents but cannot go online or receive orders.
- `suspended` — blocked by admin; tokens revoked.
- `deleted` — user asked for account deletion (required by app stores). Personal data is anonymised; financial records are kept (pseudonymised) for accounting. Deletion is blocked while the user has an unfinished order or a non-zero wallet/COD/earnings balance — the app explains what to do first.

## 2.4 Authentication rules

- Customer: **email + OTP** (primary; code sent to the email address), or email + password; Google and Apple sign-in (at launch). Phone and email are both required for every customer.
- Vendor and rider: email + OTP or email + password.
- Admin: email + password + TOTP 2FA (MUST for admins).
- Login with a role hint: an app only accepts users with the matching role (rider app rejects a pure customer). The old system did this too.
- A vendor staff user with no vendor assigned cannot log into the vendor app (clear error).
- Tokens: Sanctum personal access tokens for apps, stored in secure storage; cookie session for the Next.js admin/vendor portal. Logout revokes the token. Password change revokes all other tokens.
- OTP: sent **by email** (no SMS at launch). 6 digits, valid 10 minutes (email can be slower than SMS), max 5 attempts, resend after 60 s, rate-limited per email address and per IP. The email says which app requested it and never contains a login link. The phone number is collected and format-checked (E.164, Nigerian prefixes) but is **not verified by OTP** at launch. It is verified LATER, when an SMS or WhatsApp provider is added.
