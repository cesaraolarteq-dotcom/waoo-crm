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

## Step 4 delivered (Claude, 2026-10-15)

- Fortify `views` on: GET `/login`, `/two-factor-challenge`, `/user/confirm-password` render Blade screens (`resources/views/auth/`, tokens in `public/css/`, no build step). Fortify `home` is `/admin`; `/` redirects there. The JSON placeholder `/login` route was removed.
- Enrollment screen `GET /seguridad/dos-pasos` (`auth` + `password.confirm`, active owner_admin only): start → scan (QR + manual key) → recovery codes shown once, right after confirmation (session status), never again. It posts to Fortify's own routes.
- Filament panel `admin` at `/admin`, **no Filament login** (`hasLogin()` is false; `/admin/login` is 404). Auth middleware, persistent for Livewire: `AuthenticateAdmin` (Filament Authenticate + redirect of unenrolled owners to enrollment) and `RequireOwnerAdmin`. Staff and inactive owners get 403.
- Spanish: `lang/es/auth.php`, `lang/es/validation.php` (minimal), `lang/es.json` (Fortify 2FA strings).
- `composer.json` post-autoload-dump runs `filament:upgrade` (publishes Filament assets to `public/`, gitignored), so deploy needs no Node.
- Tests: `tests/Feature/AdminShellTest.php` (7 tests). Locally on SQLite: 17/18 pass; the InnoDB test needs MariaDB (CI).

## Codex security review (2026-10-15)

PR #19 at `9d21360` is **changes requested**: [review and reproduction](reviews/ADMIN-SHELL-SECURITY.md). AuthenticateAdmin and real persistent Livewire updates passed the revocation/tampering cases. The HTML enrollment response has no-store, but the legacy Fortify JSON routes for codes/key/QR lack it. Normal refresh hides codes; re-confirming an already enrolled owner or reading the JSON route can return the same generation again. A once-per-generation delivery guarantee is not implemented by the flash status alone. Claude should address the cache headers and align HTML/JSON delivery before another review; no anonymous disclosure was observed.

## Recovery codes and caching (after Codex review R1/R2, 2026-10-15)

- **R1:** `SensitiveAuthResponses` runs on every Fortify route and sets `Cache-Control: no-store, private` + `Pragma: no-cache` (login, challenge, confirm password, secret key, QR, recovery codes, including refusals). The enrollment screen sends the same headers.
- **R2, one-time delivery per generation:** column `users.two_factor_recovery_codes_delivered_at`. `RecoveryCodeDelivery::claim()` sets it atomically (`UPDATE ... WHERE delivered_at IS NULL`) and returns the codes only to the first caller. Both paths use it: the enrollment screen (only right after `two-factor-authentication-confirmed` or `recovery-codes-generated`) and `GET /user/two-factor-recovery-codes`, which returns **410** after delivery. Claim and every code-changing action (enable, regenerate, disable; bound to `App\\Actions\\Fortify\\Locked*`) take the same user row lock; a new generation is reset as not delivered in the same transaction that writes it, and a claim returns exactly the set it marked. Re-confirming an existing secret or using a recovery code is not a new generation and shows nothing.
- To get new codes: `POST /user/two-factor-recovery-codes` (password-confirmed session), then the screen shows the new set once.
- Tests: `tests/Feature/RecoveryCodeDeliveryTest.php` (7) + Codex's `AdminShellSecurityTest.php` (7, real Livewire update endpoint).
