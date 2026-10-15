# HoneyBook → Waoo CRM migration plan (IMPORT-01; runs before the pilot, Phase 5)

Rule: **HoneyBook stays the system of record until the owner approves the switch.** Per event only one system sends charges, contracts and messages.

## Owner steps (when we reach Phase 5; nothing needed now)
1. In HoneyBook, export what it allows. Check in your account which exports exist: **contacts/clients (CSV)**, **projects/bookings** and **payments/reports** if available.
2. Download the **signed contracts and invoices** (PDF) of every **future** event. For the ~250 archived ones, only what you want to keep.
3. Put the files in a private folder on your Mac (not in GitHub, not in chat). We import them from there.

## Import order
1. **A test sample first** (fictitious or anonymized): a future paid event, one with a deposit only, one rescheduled, one cancelled.
2. **All future events** next, as firm commitments that block crew and costumes, **before** any public booking is enabled. Events missing resource data go to "needs review" and block capacity conservatively.
3. **Archived clients** last: contact data + history summary + PDFs attached (never regenerated as if signed in the new CRM).

## Reconciliation (per event)
Total, payments received, refunds/credits, balance, contract status and date/time must match HoneyBook. Totals and counts must match globally. Repeating the import must not duplicate anything (idempotent by HoneyBook id).

## Switch-over
- Pilot cohort: new bookings from a chosen date go to the CRM; existing ones stay in HoneyBook until their event passes (or are moved one by one).
- No reminders or charges from both systems for the same event.
- Cancel HoneyBook only after: all future events reconciled, documents exported, backups restorable, and the owner's OK.
