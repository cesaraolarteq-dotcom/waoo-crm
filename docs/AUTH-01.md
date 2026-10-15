# AUTH-01 integration contract — Codex / Claude

2026-10-15: owner approved Phase 1; César `cesar@waooparty.com` and Maybeli `maybe@waooparty.com` have equivalent owner_admin permissions. [DEVELOPMENT.md](DEVELOPMENT.md) documents private CLI provisioning. No production account or password is seeded.

## Same-origin browser session

Use Laravel session cookies, `Accept: application/json` and CSRF. GET `/session/csrf`, then send the token as `X-CSRF-TOKEN` on writes with cookies retained. POST `/login` with email/password. `two_factor: true` means POST `/two-factor-challenge` with code or recovery_code before accessing CRM. POST `/logout` clears session. Login and challenge are rate limited. No public registration or password reset mail yet.

A new owner can log in but `/admin/api/session` remains 403 until 2FA enrollment is confirmed. POST `/user/confirm-password` with password; POST `/user/two-factor-authentication`; GET `/user/two-factor-qr-code`; POST `/user/confirmed-two-factor-authentication` with code. GET `/user/two-factor-recovery-codes` from the authenticated, password-confirmed session and ask the user to store them privately. Never log QR payloads or recovery codes. Disabling 2FA closes CRM access again.

`GET /admin/api/session` returns user id/name/email/role and `America/Chicago`. Only active owner_admin with confirmed 2FA is allowed. Anonymous requests return 401; other roles, inactive owners or incomplete 2FA return 403. No financial data is exposed to staff. Use the same RequireOwnerAdmin middleware on all administrative backend routes, not only a hidden UI block.

## UI boundary

Laravel login GET currently returns a JSON 401 placeholder. Claude replaces it with login UI and adds enrollment/challenge/recovery screens from COMPONENTS.md/tokens.css. Fortify handles POST routes and security. Filament 5.10.2 and Livewire 4.4.7 are resolved alongside Laravel 13.36.0 on PHP 8.3.35; User implements FilamentUser with admin-panel-only permission and mandatory 2FA. No panel/resources or designed screens are generated in this delivery: Claude owns step 4 and must apply this authorization to panel routes and Livewire updates as well as page loads. Do not enable an independent Filament password-only login that bypasses Fortify's challenge.

DATA-01 provides the initial relational schema and fake opt-in fixtures, not the complete future financial/signature/logistics model. The operational API and locking algorithm are a separate review increment; bootstrap does not expose a booking endpoint.

No Hostinger changes were made. Acceptance on the approved test site awaits deployment authorization, account passwords chosen privately, 2FA enrollment, and Claude's screens.
