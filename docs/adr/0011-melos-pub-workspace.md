# 0011. Flutter monorepo: pub workspace + Melos, shared auth package

Date: 2026-11-01 · Status: Accepted

## Context
Three Flutter apps share an API client, models, theme and the whole sign-in flow. CLAUDE.md §3 names `packages/core` and `packages/ui`.

## Decision
- `mobile/pubspec.yaml` is a Dart **pub workspace** (one lock file for all packages) with Melos 8 scripts `generate`, `analyze`, `test` used by CI.
- A third package, **`packages/auth`**, holds the splash, email login and OTP screens and the go_router auth guard, because they are identical in all three apps. It depends on `core` and `ui`; `core` holds no widgets and `ui` holds no business logic.
- Generated code (freezed/json_serializable `*.g.dart`/`*.freezed.dart` and gen-l10n output) is not committed; `melos run generate` creates it, locally and in CI.
- Flavours: `main_dev.dart`, `main_staging.dart`, `main_prod.dart` plus Android product flavours with `.dev`/`.staging` application ID suffixes. The base URL comes from the flavour or `--dart-define=API_BASE_URL`; only dev may use plain HTTP.

## Consequences
- One dependency resolution for everything; upgrades happen once.
- Building or analysing needs `melos run generate` first (documented in mobile/README.md).
