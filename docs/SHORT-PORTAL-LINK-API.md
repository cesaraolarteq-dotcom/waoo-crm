# Short client portal link — V1 contract (before implementation)

Owner request: PR103 night item8. Branch codex/quick-booking-night. This document is a design, not a deployed route. Existing full portal URLs remain valid. No sends or provider activation.

## Owner operations

Keep IssuePortalGrant and RevealPortalGrantLink signatures and response envelopes. New grants and explicit owner copy/reveal may return an origin-relative short entry URL `/r/<code>` as their `url`, without changing the underlying grant, scopes, customer, expiry or revocation. Existing grant links can be shortened lazily by an authenticated, enrolled owner. Do not issue another grant to make a short link. Preserve the encrypted original token and its existing integrity binding.

Persist one mapping per grant with a unique SHA-256 digest of a cryptographically random, case-sensitive, URL-safe code (12 characters, 72 bits from 9 random bytes), plus its encrypted recoverable value bound to that grant and digest. Never use sequential IDs, client names, phone numbers, event IDs or truncated hashes of predictable fields. Collision retries are bounded. Do not expose the mapping through model serialization or audit data. Audit only grant/event IDs, never token, code or resulting bearer URL. Owner copying must remain possible after a lost response, without extending expiry.

## Public GET /r/{code}

The URL is a bearer credential: possessing it grants the same access as the original link, never more. Exact code shape, rate limiting and sandbox/transport checks precede resolution. Invalid, absent, expired, revoked, customer-mismatched or no-longer-current grants fail closed with one generic unavailable response; no customer/event details. Recheck responsibility for payment scopes and imported-lifecycle restrictions, and quote revision validity using the same authoritative rules as existing reveal/session paths. Never call an owner-only service with a fabricated actor.

A successful GET redirects to the appropriate existing portal page with the original token in the fragment; the existing session exchange and mutation consent remain required. The GET neither accepts a quote, signs a contract, consumes a one-time approval, confirms capacity, creates a checkout nor records payment. Link-scanner GETs must not have business effects. Do not extend TTL. Quote grants use their existing quote entry page, regular grants the existing portal entry page.

All resolution responses use `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex, nofollow`; no third-party assets or analytics on the entry route. No token/code in query strings, logs, exception text or audits. Since a path bearer can appear in host access logs, deployment review must explicitly redact `/r/*` and redirect Location in proxy/APM logs; this document does not claim host configuration has been changed. Use only the configured validated sandbox origin, never a caller-controlled redirect target. Revoke/expiry of the underlying grant immediately invalidates both links, including an existing portal session under current middleware.

## Verification before delivery

Synthetic tests: repeat owner copy returns the same recoverable link; unauthorized owner/staff denied; old full links still work; exact case/shape and unknown code; expiry/revocation/customer change; old quote revision; HoneyBook responsibility gate; redirect fragment and existing session exchange; safe headers on success/failure; no mutation on GET; secret-free audit; encryption/tampering fails closed; collision handling; concurrent shortening produces one mapping. Actual Hostinger log policy and browser transport remain deployment checks, not inferred from local tests.

Implementation pending. This contract does not complete night8 or earn progress credit.
