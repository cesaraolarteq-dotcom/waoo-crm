# SMS review boundaries, 2026-10-29

Owner reports Twilio active and authorizes activation once the entire production
migration is complete. Test delivery remains off; credentials alone do not enable SMS.

Website intake may establish a first opt-in but must never overwrite an existing
STOP. Only the signed, deduplicated inbound re-subscription path may restore that
phone after STOP. A provider timeout/5xx or malformed successful response is an
unknown outcome, never a confirmed rejection; do not retry it automatically.

The original Claude151 stack is integrated through #164. Further protocol work remains: stable per-attempt idempotency and
durable dispatch/callback correlation, current event/customer/responsibility
binding and transaction-bound consent rechecks need coverage. Twilio configuration
must include the inbound verification token and secure callback origin before
delivery can be ready. No live provider call is part of this review.

The two regression fixes here preserve STOP during later website submissions and
classify uncertain sends truthfully in owner UI. They do not claim the full
dispatch protocol complete or authorize deployment. Original money and paid-draft report reads were reviewed in #164.
