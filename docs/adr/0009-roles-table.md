# 0009. Roles in a plain table, permissions in policies

Date: 2026-11-01 · Status: Accepted

## Context
Spec 02 defines seven roles (customer, vendor_owner, vendor_staff, rider, admin, super_admin, zone_admin). A user can hold several. What each role may do is spelled out in the permission matrix (spec 02 §2.2) and depends mostly on ownership ("own orders", "own vendor") and on zones, not on a list of named permissions. The candidates were `spatie/laravel-permission` or a small table of our own.

## Decision
- A plain `roles` table plus a `role_user` pivot. The seven roles are inserted by the migration that creates the table, so every environment has them without a seeder.
- `App\Enums\RoleName` lists the roles in code; `User::hasRole()` and `User::assignRole()` are the only helpers.
- What a role may do lives in Laravel **policies and gates** (e.g. `view-audit-log`), next to the ownership and zone checks they need.
- Which roles may sign in to which app is fixed in `App\Enums\ClientApp::allowedRoles()`. Each Sanctum token carries one `app:*` ability, so access needs both the right token and the right role.
- Roles are never mass-assignable and never accepted from a client (legacy-notes D1).

## Why not spatie/laravel-permission
- Its value is a runtime-editable permission list. Our rules are ownership and zone checks that a permission name cannot express, so we would still write policies.
- It adds guard names, caches and five tables to keep in sync with Sanctum's token guard, which is more to get wrong for little gain.

## Consequences
- Fewer moving parts; role checks are plain queries and easy to test.
- The admin "permissions per role" screen (spec 12.2) is not supported by this design. If the owner wants admins to edit permissions at runtime in Phase 6, revisit this ADR; moving to spatie later means a data migration from `role_user`.
