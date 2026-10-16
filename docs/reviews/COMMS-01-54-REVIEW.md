# COMMS-01 / PR #54 and pending PR checkpoint

2026-10-16 America/Chicago. Source review, not a formal GitHub review submission (API previously Forbidden). Exact Git refs fetched; no Claude history rewritten.

| PR | Exact head | Verdict |
| --- | --- | --- |
| #54 | b30d209, own delta after f9b93c8 | Request changes: recipient validation and warning lifecycle; inherits blocked #49 |
| #44 |150941f | Approve with the two existing copy corrections: margin uses agreed revenue, unconfirmed costs are not confirmed zero |
| #46 |e173d7a | Request changes: imported active recipes omit declared equipment; no new fix in head |
| #47 |284dc38 | Approve; nonblocking `[hidden]` CSS override note remains |
| #48 |faac790 | Approve; no blocking finding |
| #49 |f9b93c8 | Request changes: pre-booking SaveCustomer remains; backend correction ad87fae is published but not connected |

Older five-PR findings/probes: docs/reviews/CLAUDE-47-46-48-49-44.md. Their heads are unchanged. This checkpoint does not claim fresh runtime validation of #44/#46/#47. The review integration branch includes #54 and its #48/#49 parents on the separately delivered backend stack; it is for review/reproduction only, not a merge recommendation for blocked source.

## #54 P2: extensions/free text become a different recipient

app/Filament/Support/ContactLinks.php:10–14 deletes every nondigit then accepts any11–15 digits. `(346) 555-0100 ext 123` becomes `3465550100123`, producing `https://wa.me/3465550100123?...`, instead of rejecting the extension. A bearer portal link may therefore be offered to a different international recipient. `DO NOT TEXT 346-555-0100` also generates a send link; eleven zeroes are accepted.

Use a conservative phone parser: supported US10 digits/11 with leading1, or explicit international `+` with a valid country/number shape; reject letters/extensions/ambiguous free text and all-zero/invalid NANP forms. A library is optional; do not silently infer a foreign country from malformed local data. Invalid phone → no send buttons, visible correction/copy fallback. Keep encoding the full message so portal `#token`/`&` remain message content. Add regression tests expecting null/rejection for the reproduced inputs; a passing diagnostic below only proves the bug.

## #54 P2: global unload handler survives the result it protects

resources/views/filament/pages/event-contract.blade.php:18 and quick-booking.blade.php:8 register window listeners in x-init without removal. Removing a result with hideLink/startOver or wire navigation leaves its handler and old scope alive. The explicit “Ya lo copié” action does not update that scope. This can warn later on unrelated navigation. Clicking WhatsApp/SMS sets sent=true even if no handler opens or the owner cancels; opening a composer is not sending.

Use an Alpine-owned window directive or destroy cleanup, and acknowledge only successful clipboard/manual confirmation. Do not claim delivery or set a persisted sent state from a click. Rename SMS action to “Abrir SMS” if appropriate. Once recopy is implemented, remove this warning if it adds friction; active links remain recoverable. beforeunload is only best effort and does not cover every mobile/app/Livewire navigation. Lifecycle finding is static; actual iOS/Android/desktop composer behavior remains unverified.

## Good behavior retained

Buttons build URIs only; no provider/API sends. rawurlencode + Blade/@js escape the message, including the token fragment. Owner-only screens retain existing no-store/no-referrer middleware. The new phone is from the owner-visible customer record, not a browser-selected provider account. Copy fallback remains necessary on desktops/mobile without a configured SMS handler. Do not use external opening/clipboard acknowledgement as consent, delivered evidence or permission to automate messages.

## Verification

On codex/review-comms-54 (exact #54 merged into existing tested backend stack): **41 tests /446 assertions PASS**, PHP8.3/MariaDB11.8, covering QuickBookingPage, EventAutoTitle, ContractScreens, ClientPortalPage and QuickBookingCore; Vite build PASS. No new full-suite, browser or remote-CI claim.

Diagnostic **2 tests /5 assertions** reproduce unsafe number acceptance; outside normal suites:

```sh
docker exec -e PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/tools/runtime/test-php-ini waoo-phase1-runtime vendor/bin/phpunit docs/reviews/probes/Comms54PhoneProbe.php
```

Run only on this review branch (which contains ContactLinks). No real recipient, message, provider request, charge or deployment. Recommendations/API contract are published separately on codex/comms-01-contract without merging blocked #54 UI source.
