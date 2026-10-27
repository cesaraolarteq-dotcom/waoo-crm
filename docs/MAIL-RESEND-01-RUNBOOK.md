# Resend preparation with every email held · 2026-10-27

Owner instruction: no outgoing emails until the CRM is fully operational. **Do not send a test email, including to César, while this hold is in effect.** Selection/DNS/key/deployment/green tests do not authorize delivery. Implementation scope: [API contract](MAIL-RESEND-01-API.md).

## Preparation (no email)

1. Cross-review the provider adapter and master hold. Keep `WAOO_MAIL_DELIVERY_ENABLED=false`, `WAOO_SANDBOX_MAIL=false` and `WAOO_SANDBOX_QUICK_PAYMENT_NOTIFICATIONS=false` unless a separate sandbox capture-only workflow is already authorized. The master hold remainsfalse even when capture-only sandbox_mail istrue. Existing provider flags do not override it.
2. Leave Laravel `MAIL_MAILER` unchanged; never switch it to SMTP/Resend as a connection test. The new MessageSending listener stops framework delivery while held. Check existing queue workers/config caches when an authorized deployment eventually installs this hold; this work did not change the host or inspect private environment values.
3. Claude/César may prepare Resend account, verified domain and DNS privately under their existing authorization. Use exact records shown by Resend; do not invent record values or replace Hostinger root mailbox MX/SPF records. Sender/reply-to proposed in #133 is sales@waooparty.com, not a verified fact or permission to send. Store keys only in private host configuration; no screenshot/key pasted to Git/chat.
4. Configuring `WAOO_MAIL_PROVIDER=resend`, RESEND_API_KEY and explicit WAOO_MAIL_FROM_ADDRESS/NAME/REPLY_TO is still preparation: master holdfalse keeps the bound transport Disabled. A direct Resend adapter call also rechecks the hold before HTTP. Config defaults are disabled/empty. No provider request is needed to read configured/hold state.
5. Run synthetic/mocked tests only. Admin mail settings expose `delivery_enabled=false` separately from sandbox capture and `transport_configured`. Claude should show the owner hold clearly in the readiness screen; backend adds no client wording or automatic activation action.
6. Receiver preparation is separate: WAOO_SANDBOX_RESEND_WEBHOOKS defaultsfalse, signing secret private, HTTPS/stateless Svix verification. An authenticated inbound event never opens sending. If later authorized to register a webhook, select only the supported delivered/bounced/complained types; unknown/delay types are evidence-neutral. No webhook was registered here.

## Before a later explicit activation instruction

- Complete and accept the operational CRM using simulated email delivery while this instruction prohibits real tests. Do not claim actual deliverability from mocks. The first actual provider delivery test must wait until César explicitly lifts the hold.
- Review the delivery transport, sender/domain status, approved ES/EN texts, recipient rules and bounce handling; reconcile any sent/dispatching/delivery_unknown record. Never automatically retry an uncertain accepted request or infer success from a timeout. Resend idempotency keys are not permission to resend after retention expires.
- Review all scheduled/blocked/old mail and queued framework mail before enabling; no bulk historical catch-up. Do not reset message states or resend deleted/source-retired emails as a setup step.
- Complete the parallel QuickPaymentNotification receipt/suppression adapter if that channel is needed: Resend currently refuses that channel before it is claimed, preventing integer-ID collisions and unknown receipt mappings. It is a known implementation gap, not a task for César to price/decide.
- Current concrete Resend adapter is sandbox-only and owner-allowlisted. Production recipient/activation policy requires its own reviewed delivery; setting a flag cannot bypass Sandbox or widen recipients. No production-ready/full-email-completion claim is made here.
- Only after a new explicit owner activation instruction: identify the reviewed deploy/version, rebuild configuration/restart any relevant workers in the authorized host workflow, verify gates and begin the separately approved bounded rollout. Reapplying the hold also requires refreshed worker configuration. No UI/progress percentage/time-based switch is implemented.

## If something fails

Keep delivery held. Do not “try another email” to diagnose. Inspect local mocked tests and aggregate status/configuration only; never log message bodies, private links, Authorization headers, keys or webhook bodies/signatures. Missing webhook send mapping is503 for provider retry; recipient mismatch422; same event ID with conflicting intent409. Authenticated terminal failures preserve suppression, late delivery never unsuppresses, and incoming evidence can be retained independently of the sending hold.
