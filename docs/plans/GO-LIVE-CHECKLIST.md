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
