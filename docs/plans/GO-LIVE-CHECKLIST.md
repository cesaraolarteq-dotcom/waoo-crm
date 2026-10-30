# Owner activation authorization · 2026-10-30

César: «puedes activar los envios una vez migremos todo al crm real lo pasemos del test puede ser algo simplemnte automatico una vez estemos en el real igual que los pagos».

This supersedes the indefinite sending/payment hold **only after the authorized production migration is complete**. Prepare a single cutover activation step for email, SMS and payments; do not ask for another business approval for those switches at that point. The deployment itself still requires César's “súbelo”. crm-test remains held and uses test payment providers.

The cutover step must verify the production origin, completed/reconciled migration, live provider/account/webhook configuration and the complete reservation/payment path before enabling the existing delivery/payment gates. A hostname or APP_ENV change alone is not proof of migration completion. Preserve SMS consent/STOP, campaign opt-out, per-event pauses and duplicate-send protection; do not replay obsolete test or historical messages when activating.

Implementation still pending: PaymentServiceProvider currently binds StripeTestGateway/StripeTestRefunds and Finance\Sandbox refuses production. Live adapters and explicit migration-completion evidence must be implemented/tested before the automatic activation step can succeed; this decision does not remove those guards or claim live readiness. No credentials or production data belong in this file.

# Go-live checklist (before real clients use the CRM)

| Area | Item | Owner of the task |
| --- | --- | --- |
| Legal | Lawyer review of the contract template v1 and of the in-house e-signature process (consent, evidence, retention) | Owner (+ lawyer) |
| Legal | Privacy policy updated for the client portal (data stored, payments by Stripe, emails/SMS, retention, contact) — website `privacy.html` | Claude (draft) + Owner |
| Payments | Stripe **live** keys set on the production server by the owner; webhook endpoint for production; 3% card fee rule confirmed (credit only, disclosed) | Owner + Codex |
| Payments | Zelle instructions (business name, number) confirmed; reference format WP-#### | Owner |
| Email | sales@waooparty.com verified with the email provider (SPF/DKIM on waooparty.com); test emails land in inbox, not spam | Codex + Owner (DNS) |
| SMS (Track B) | Twilio A2P 10DLC registered; opt-in/STOP wording | Owner + Codex |
| Hosting | Production subdomain `crm.waooparty.com` (HTTPS), app outside web root, cron set, `.env` only on server | Owner (hPanel) + Codex |
| Backups | Automated encrypted daily backup **off Hostinger** (cloud storage), restore drill done and timed | Codex |
| Security | 2FA on both admin accounts; portal links expire; staff cannot see pay/finance; rate limits on login and forms | Codex + Claude (tests) |
| Data | HoneyBook future events imported and reconciled (HONEYBOOK-MIGRATION.md) | Codex + Owner |
| Operations | Runbook: how to verify a Zelle, cancel/reschedule, refund a card, restore a backup, pause online payments | Claude (owner-facing) + Codex (technical) |
| Cleanup | Remove the Phase 0 probe app and test DBs, the throwaway Stripe test endpoint, and the SSH key if no longer needed | Claude/Codex + Owner |
| Pilot | 2–4 weeks of real bookings in parallel, weekly check with the owner | All |
