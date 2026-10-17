# New large-ticket contracts: backend review

2026-10-17 · Codex · source main98b3435 (#61/#62). Owner requested order: QUOTE-01 → IMPORT-01 → MAIL-01/MSG-01 → AVAIL-WEB-01. This accepts that work order and describes required integration details; it is not approval of email wording, provider activation, prices, availability promises or deployment.

## QUOTE-01

Direction accepted. Shared API published first2d90794, docs/QUOTE-01-API.md; owner foundation source88d53f3. Separate immutable quote revisions from current aggregate status and keep approval actor/time server-derived. Revision history survives changes in catalog labels, selected PriceVersions, characters/outfits, draft versions or travel evidence. Explicit discounts never rewrite the base price; integer final/deposit amounts handle odd cents.

First source is an existing audited draft with an explicitly chosen customer; there is no invented provisional identity or auto-merge. Quote creation does not take money or hold resources. QUOTE B must bind each quote-specific grant to one revision/customer/event and enforce an independent scope; old links cannot silently open replacement offers. Acceptance is not the later contract signature. C must preserve accepted amounts end to end: today's CreateFinancialRevision and ConfirmBooking still compute undiscounted catalog+travel totals and cannot simply be called for a discounted accepted quote. Update the finance/contract/capacity contract together and prove the discounted50% target uses the accepted amount without a fabricated deposit exception. Keep QuickBook's post-core link-recovery boundary intact.

Claude can build A's owner list/details/create/revise now, using the published services, Locked quote/event versions and stable retry UUID. Quantity per distinct service is1 in A, consistent with existing drafts; don't add an unsupported quantity/price override in the UI. Show source draft saves explicitly rather than hiding a separate customer update in a quote-save action. Client send/accept/PDF/convert remain disabled until B/C are delivered.

## IMPORT-01

Direction accepted; implementation follows QUOTE. Synthetic CSV fixtures suffice for backend work; do not request credentials or commit an owner export. Preview is a private proposed mapping, not an import or authority to activate capacity. Parse timezone/ambiguous Houston times explicitly; missing monetary splits/time/resource data must stay needs_mapping rather than becoming zero or inferred confirmation.

Keep external project identity separate from external payment identity/content revisions. The same file must not duplicate money; a changed later export cannot overwrite an immutable ledger. Preserve HoneyBook source and original agreed amounts rather than silently using current catalog prices. Mapping a legacy package to an operational recipe must not insert an old price as the new public catalog price. Exact financial-bridge work from QUOTE C should support this distinction, with import-specific provenance rather than fabricated quote acceptance.

No auto-merge on name/phone matches; ambiguity needs an owner choice. Future confirmed events are only operationally covered once resources pass the normal capacity checks. Reconciliation needs unimported/unmapped/unreserved/conflicting rows and financial differences; pilot availability cutover must remain blocked while those gaps remain. No client message or external charge follows an import.

## MAIL-01/MSG-01 and draft v1 templates

Sending architecture needs append-only rendered intent/provider-event evidence plus a mutable delivery projection. An immutable row cannot also be updated from queued→delivered→bounced. Stable deduplication includes channel/template key+version/locale/entity+revision; webhook event/provider IDs also deduplicate. Recheck cancellation/reschedule/payment, event pause, expiry and suppression at dispatch time, not only when queuing. Delivered is not inbox placement or reading. Use the provider's actual documented webhook authentication mechanism; do not invent a universal HMAC signature header. No provider/DNS/send activation in this review.

**Request for Claude — draft corrections before owner approval/rendering:**

1. `quote_sent` needs an explicit conditional discount line and final total when a discount applies. `package_price` and `valid_until` are used but absent from the top variable declaration. Also declare `amount` and `address`, used by other templates. The backend must reject missing required placeholders, not silently render an incomplete offer.
2. `payment_received` uses `{{#balance}}` as a condition while displaying a money-formatted balance. A formatted `"$0.00"` is truthy in Mustache. Use a separate server-derived `has_balance = balance_cents > 0` boolean for due-date wording; never infer positivity from the formatted string.
3. `balance_reminder` heading says “24h before the due date,” while the ticket's job describes the balance-due point24h before the party. Those are48h vs24h before the event. Align the trigger definition before implementation; the payment deadline remains event_start minus24h. Any additional earlier courtesy reminder must have its own defined trigger rather than moving that deadline.
4. Dynamic subjects with a long first name/event date can exceed the claimed60-character limit. Render-time length/escaping tests must cover long names, HTML-like names, Unicode, zero/partial/overpaid balances and missing optional fields. Never truncate/alter legal terms or amounts to satisfy a subject limit.
5. Keep a client-visible distinction between quote acceptance, received money, reviewed details, signed contract and confirmed resources. The details_request “request recorded” wording is appropriate; no template should promise that paying alone reserves availability.

Texts in docs/templates/email/MAIL-01-templates-v1.md remain unapproved and inactive. This review does not rewrite them or grant consent to send. Owner review link/Zelle wording/card disclosure and actual email/DNS/provider checks are later activation inputs. SMS remains first automated companion channel per the owner; WhatsApp is complementary, not assumed to reach every client.

## AVAIL-WEB-01

Direction accepted with fail-closed evidence. Coarse levels/alts can remain public and cached with strict output/rate bounds; website still falls back to iCal and must never block an inquiry. Differentiate marketing demand from measured capacity: the next8 weekend rule may display demand wording but cannot fabricate occupied staff or turn unknown into verified open.

ZIP/service alone cannot prove an exact party-to-party trip with the owner's45min contingency, traffic at the target weekday/time, breakdown/setup or a15min owner exception. Missing inventory, imported future commitments, transport/driver/seat evidence, exact address or short-notice owner approval must produce unknown/review where required. Don't run Google/provider calls from a public uncached request or expose names, locations, exact counts or internal reasons in the public response. Expire/cache invalidation follows operational changes, not just a five-minute happy-path cache.

This ticket is separate from ROUTE-01's price calculation. The22 recorded pricing cases passing does not validate traffic-aware party-to-party availability. Real provider/website/fallback/cutover tests remain required and nothing public is activated here.
