# WeGoDeliva mobile (Flutter + Melos)

Three apps sharing three packages, managed as a Dart pub workspace with Melos.

| Path | Package | What |
| --- | --- | --- |
| `packages/core` | `wegodeliva_core` | Dio API client (base URL per flavour), freezed models, the API error model (spec 14 §14.1), token storage in Keychain/Keystore (`flutter_secure_storage`), Riverpod auth state, logger that masks user data, FCM push stub |
| `packages/ui` | `wegodeliva_ui` | Material 3 theme from the brand tokens (navy `#0B1220`, orange `#FF6B1A`, navy text on orange), light/dark, 48dp tap targets, base components with semantic labels |
| `packages/auth` | `wegodeliva_auth` | Splash, email login and OTP screens (gen-l10n), and the go_router auth guard shared by the apps |
| `apps/customer` | `customer_app` | "WeGoDeliva" — `com.wegodeliva.customerapp` |
| `apps/vendor` | `vendor_app` | "WeGoDeliva Vendor" — `com.wegodeliva.vendorapp` |
| `apps/rider` | `rider_app` | "WeGoDeliva Rider" — `com.wegodeliva.riderapp` |

Phase 1 apps are shells: splash → email → 6-digit code → placeholder home, against the real
backend endpoints (`/auth/otp/send`, `/auth/otp/verify`, `/auth/me`, `/auth/logout`).

## Setup

```bash
dart pub global activate melos
cd mobile
melos bootstrap          # flutter pub get for the whole workspace
melos run generate       # freezed/json_serializable + gen-l10n (generated files are not committed)
melos run analyze        # zero issues required (infos and warnings fail CI)
melos run test
```

## Flavours

| Flavour | Entry point | Android ID suffix | API base URL (default) |
| --- | --- | --- | --- |
| dev | `lib/main_dev.dart` | `.dev` | `http://10.0.2.2:8000/api/v1` (local backend from the Android emulator) |
| staging | `lib/main_staging.dart` | `.staging` | `https://api-staging.wegodeliva.com/api/v1` (placeholder until staging exists) |
| prod | `lib/main_prod.dart` | none | `https://api.wegodeliva.com/api/v1` (placeholder) |

```bash
cd apps/customer
flutter run --flavor dev -t lib/main_dev.dart
flutter run --flavor staging -t lib/main_staging.dart --dart-define=API_BASE_URL=https://…
```

Staging and production refuse non-HTTPS base URLs. Cleartext HTTP is blocked on Android except,
in the dev flavour only, to `10.0.2.2`/`localhost` (`android/app/src/dev/res/xml/network_security_config.xml`).

## Platform settings

- **Android:** `compileSdk`/`targetSdk` 36, application IDs as above (unchanged from the
  existing Play listings), flavour app names via `resValue`, predictive back enabled
  (`enableOnBackInvokedCallback`), edge-to-edge is the default at API 35+.
  Release signing is a placeholder (debug key); the real upload key will come from CI secrets
  in Phase 7 after the Play Console upload-key reset. Never commit keystores or `key.properties`.
- **iOS:** bundle IDs are set to the same IDs. **Not done yet (needs a Mac with Xcode):**
  per-flavour schemes/xcconfigs with the `.dev`/`.staging` suffixes, the privacy manifest
  (`PrivacyInfo.xcprivacy`), Sign in with Apple capability, and signing.

## Push notifications (FCM)

`PushService` in `core` is a no-op for now. To wire FCM once the team has access to the
existing Firebase project:

1. Add `firebase_core` and `firebase_messaging` to `packages/core`.
2. Run `flutterfire configure` locally per app and flavour. It writes
   `google-services.json`, `GoogleService-Info.plist` and `firebase_options.dart`; these are in
   `.gitignore` and must be provided to CI from secrets, never committed.
3. Implement `PushService.deviceToken()` with `FirebaseMessaging.instance.getToken()` and send it
   to `POST /api/v1/devices` after sign-in.

## Rules

- Strings go through gen-l10n (`lib/l10n/*.arb`); English only at launch.
- No `print`; use `AppLogger`, which masks emails, phone numbers, tokens and codes and is silent
  in release builds.
- Tokens only in `SecureTokenStorage`.
