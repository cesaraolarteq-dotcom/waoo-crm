# Transfer margin30, owner decision2026-10-29

César asked to use30minutes instead of45, accounting for setup, breakdown and
travel between parties. Preserve the existing authoritative calculation's meaning:

`minimum gap = travel_minutes + max(30, breakdown_before + setup_after + contingency_minutes)`

The30replaces the old45floor. It is not an extra30on top of setup/breakdown;
longer actual work plus the recorded contingency wins. Exact equality fits.
Unknown timings or missing/version-stale route evidence stay blocked. Departure
must still equal the first party's end plus its breakdown, and traffic evidence
must match that date/departure. Actual blocks during the transfer still apply.

`TransferBufferPolicy` is shared by confirmation and the future read evaluator.
This change does not invent a route, remove the contingency evidence, apply an
unapproved early-arrival exception, release a hold, move a party or activate the
website. Existing historical review/evidence remains unchanged. No new client copy.
