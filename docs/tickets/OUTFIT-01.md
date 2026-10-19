# OUTFIT-01 · Costume versions per character (owner request, 2026-10-16)

**Owner amendment2026-10-19:** a physical costume/head and its selectable wardrobe are distinct. Latest contract: [OUTFIT-01-GENERIC-UNITS-API.md](../OUTFIT-01-GENERIC-UNITS-API.md). This supersedes the V1 restriction to fixed outfits: a generic unit can satisfy a requested current wardrobe; explicit per-version counted units remain fixed. No implicit additional physical units or wardrobe stock.

Owner: “Sometimes they ask for Mickey in a specific costume (Disneyland, Racing), or one of the two K-Pop Demon Hunters outfits. Only a few characters have more than one version; the rest have one. Ideally optional, or a note when adding the character.”

Status: backend implementation on `codex/outfit-01`; API contract `docs/OUTFIT-01-API.md` published first (7c8dcb1). Final verification evidence is recorded in HANDOFF; Claude screens, independent review/main merge and owner inventory remain pending. No price change: a version never changes the price unless the owner later says so.

## Data (additive, nullable, backfill-free)

- `characters.outfits`: JSON list of distinct version names (1–60 chars, ≤20), owner-managed, e.g. `["Clásico","Disneyland","Carreras"]`. Empty/null = single-version character (today's behaviour).
- `resources.outfit` (costume units only): nullable string that must be one of its character's `outfits`. Null = generic/unspecified unit.
- `event_service_characters.outfit`: nullable, must be one of the character's `outfits` at save time; `event_service_characters.note`: nullable ≤200 (free text, e.g. “sin orejas grandes”). Quantity > 1 with different versions = separate rows.

Renaming/removing a version must not rewrite history: snapshots keep the text; validation applies to new saves only (owner sees “versión ya no existe” on edit).

## Services

- `SaveCharacter`: accepts `outfits` (normalized, distinct, trimmed). Removing a version still referenced by an active costume unit → 409 with a clear message.
- `SaveResource` (costume): accepts `outfit`, validated against its character.
- `SaveEventDraft` / `QuickBook` / `CharacterRecipe::compile`: accept optional `outfit` and `note` per character row; `outfit` validated against the character. When `outfit` is set, the costume requirement for that row must be satisfied by a costume unit **with that outfit**; when null, any unit of the character (current rule). No silent fallback to another version.
- Availability/ConfirmCapacity: the same rule; a conflict message names the version (“No hay traje Mickey Disneyland libre a esa hora”).
- Snapshots: `CreateFinancialRevision` character snapshot, `ApproveClientDetails` recipe comparison, contract print context and the client portal include `outfit` (and `note` only owner-side; the note is NOT shown to the client unless the owner later asks).
- Contract/PDF/portal label: “Mickey (Disneyland)”. English contract label uses the owner's version text as typed.

## Screens (Claude)

- Catalog → Personajes: “Versiones del traje” tag input; costume unit form: “Versión” select (only when the character has versions).
- Event form + Reserva rápida, per character row: “Versión del traje (opcional)” select shown only for characters with versions, and “Nota del personaje (opcional)”.
- Event file / Apartar recursos / Inicio: show “Mickey · Disneyland”.

## Tests (minimum)

Single-version characters unchanged; version validated on save; reservation picks only matching units; no fallback; snapshots/contract show version; removing a referenced version rejected; two events requesting the only Disneyland unit at the same time → one 409 (real two-process race, as other capacity tests).

## Owner data needed

Per character: version names and how many costume units of each (e.g. Mickey: Clásico 1, Disneyland 1, Carreras 1; Rumi/Zoey/Mira: outfit A/B). Collected in the catalog page (“Versiones de traje”).
