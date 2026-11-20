# 0013. npm audit blocks merges on shipped dependencies only

Date: 2026-11-20 · Status: Accepted

## Context
CI ran `npm audit --audit-level=high` over every npm package in `web/`, including dev tooling (ESLint, Vitest, Vite). On 2026-11-18 a high-severity ReDoS advisory for `picomatch` (GHSA-fc5q-39xc-vjpf, all versions up to 4.0.7, the latest release) made every PR touching `web/` or the CI workflow fail, including routine Dependabot updates, with no patched version to upgrade to. All 12 high findings were in dev dependencies, none in code shipped to the admin panel.

## Decision
- The blocking check is `npm audit --omit=dev --audit-level=high`: a high or critical advisory in a production dependency still fails CI and blocks the merge (branch protection requires the Web job).
- A second step runs the full `npm audit` on every run, prints the report and raises a GitHub warning annotation, but does not fail the job.
- CLAUDE.md §4 ("`npm audit` … in CI") is still met. Composer and Flutter audits are unchanged.

## Consequences
- Dev-tool advisories no longer block unrelated work; they stay visible in CI logs and through Dependabot alerts, and are fixed when a patched version exists.
- A malicious or vulnerable dev dependency can still affect developer machines and CI runners. The risk is accepted for advisories like ReDoS in build tooling that only processes our own files; anything worse (e.g. remote code execution in a dev tool) is treated as urgent and fixed or pinned by hand.
