# MAIL previews: #69 then #70

2026-10-17 · Codex. **Approve #69 d5e54d1 as local/testing synthetic preview; approve #70 40df01d as owner-only, read-only draft settings.** Sending, approved wording, deployment and whole MAIL-01 completion are separate and not approved by this review. No formal GitHub approval submitted by this tool session; native Git/HANDOFF is the published review channel.

#69 unchanged from prior review42445d8: nine bilingual drafts, escaped table/inline layout, fixed synthetic data, closed keys, /dev/mail index and templates404 outside local/testing. Original separate3/97 tests passed; #70 retains the guard and moves synthetic rendering into MailTemplatesDraft::preview, so admin iframes need no public preview endpoint. Subject72>60 issue remains nonblocking for synthetic design; real sender prerequisites remain in MAIL-69-REVIEW.md.

#70 adds EmailTemplates to the existing persistent owner/2FA-protected panel, not a new public route or sender. Eighteen ES/EN srcdoc previews are attribute-escaped and have an empty sandbox (no allow-scripts, allow-same-origin, forms/popups/top-navigation exceptions). Browser language controls are local Alpine state; no persistence/send action. Render inputs remain fictional constants. Draft/no-send notices are visible. The page works without outbound_messages; event timeline, bounce card, allow-list and actual delivery still await the published MAIL/MSG backend API. EmailTemplates need not be restricted to local/testing because it remains an authenticated owner settings page; /dev/mail remains restricted.

## Merge registration contract

Keep **one** ->pages([...]) and take the union, not one branch's entire old line. Compared actual provider lists from #59 claude/lead-ui3bb4c3f and #65 claude/quote-uiacfbb32 (provider comparison only, not a fresh approval of those heads):

`Dashboard::class, Calendar::class, QuickBooking::class, Leads::class, Quotes::class, QuoteDetail::class, ContractTemplates::class, EmailTemplates::class`.

Use the existing fully qualified names/imports and register each once. Add Leads/Quotes/QuoteDetail only with their corresponding merged source files; #70 alone correctly does not reference absent classes. After combining, verify all four screens' routes/navigation and the existing normal page list. Preserve their Locked versions and contact tests. This review branch tests #69/#70 on current backend; it does not claim to have merged/tested the complete #59+#65 UI stack.

## Verification

Isolated branch codex/review-mail-70: source09fa9a8 is the original40df01d six-file diff atop reviewed #69; QA8e11766 adds2 authorization/Livewire boundary tests. Existing5 tests pass107 assertions. Final original+QA: **7 tests/128 assertions PASS**,6.132s, PHP8.3.35/MariaDB11.8. Tests cover18 sandboxed escaped frames, bilingual content, guest redirect, active enrolled owner, denied staff/inactive/non-2FA, no-store, real login/2FA and signed Livewire refresh allowed initially then forbidden after persisted role revocation. Mail/queue fakes assert no send/queue. PHP-FPM request isolation modeled with the established Livewire::flushState helper.

Temporary app tree reused existing dependencies/build assets, with canonical autoload/base-path remapping and forced disposable test DB. Initial harness404s came from unnormalized Composer class-map paths, fixed only in temporary bootstrap; no source fix required. Diagnostic419 was an invalid previewHtml call on a non-page snapshot; final probe uses the real signed refresh, includes session CSRF and a successful authorized control before role revocation. No assertion/permission bypass or application change to make tests pass. Syntax and git diff checks pass. Parent full465/3973 belongs to backend5dbb7d2, not a new full-suite claim for this UI review. No dependency change/new Vite build, browser/email-client rendering, provider/DNS/delivery/remote-CI test or actual data imported.

Request for Claude: keep QA with the screen on integration; preserve the single union list when merging #59/#65/#70. Before real sending, fix bounded rendered subject dates (actual Spanish sample72), replace draft renderer with approved/versioned strict inputs, pinned totals/discount labels, has_balance and matching plain text, as already recorded for #69. Continue timeline/bounce/allow-list against the forthcoming explicit outbox API; no live sends or deployment.
