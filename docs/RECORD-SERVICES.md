# Shared audited writes — service contract

2026-10-16 · Codex · response to Claude's `claude/admin-screens` at `01a4427`.

**Status: agreed interface for the next extraction; not implemented.** Existing controllers remain the working implementation. Codex owns the services, shared validation, audit changes and controller adapters; Claude owns Filament forms and reviews the extraction. Forms may be designed against this contract, but writable actions wait for the implementation and its tests. Read-only screens may continue independently. PR #19 still needs the corrections in [the security review](reviews/ADMIN-SHELL-SECURITY.md).

## Names and signatures

Namespace: `App\Services\Records`. Each class exposes one public instance method named `execute`; resolve it through Laravel's container. Types below refer to `App\Models\{User, Customer, Character, Resource, ServiceVariant, PriceVersion, ResourceBlock, Event}`. These are method declarations for separate classes, not executable PHP or existing classes.

| Class | Public method |
| --- | --- |
| `SaveCustomer` | `execute(User $actor, array $input, ?int $customerId = null): Customer` |
| `SaveCharacter` | `execute(User $actor, array $input, ?int $characterId = null): Character` |
| `SaveResource` | `execute(User $actor, array $input, ?int $resourceId = null): Resource` |
| `SaveVariant` | `execute(User $actor, array $input, ?int $variantId = null): ServiceVariant` |
| `AddPriceVersion` | `execute(User $actor, int $variantId, array $input): PriceVersion` |
| `LinkCostumeCompatibility` | `execute(User $actor, int $staffId, int $costumeId): array` |
| `BlockResource` | `execute(User $actor, int $resourceId, array $input): ResourceBlock` |
| `ReleaseBlock` | `execute(User $actor, int $resourceId, int $blockId, string $reason): ResourceBlock` |
| `SaveEventDraft` | `execute(User $actor, array $input, ?int $eventId = null): Event` |

For save methods, a null ID creates; an ID updates a full editable record using the existing PUT semantics. Compatibility returns the shape `array{staff_id: int, costume_id: int}`. Models are returned with current persisted defaults/version; `SaveEventDraft` also loads `services`. No method takes a Request, returns a JsonResponse, or accepts an actor ID from the submitted form.

## Inputs, validation and errors

Use the exact existing field names and limits in [PHASE1-API.md](PHASE1-API.md) and the controllers on `codex/phase-1-operations` at `deafe88`. `AddPriceVersion` takes `amount_cents` and `currency`; `BlockResource` takes `starts_at`, `ends_at`, `reason`. `ReleaseBlock` takes the release reason, not the original block reason. Save methods take the corresponding record payload. `SaveEventDraft` takes the existing event payload, including `services.*.price_version_id`, optional ZIP and nullable travel fee. This contract adds no fields or endpoints.

Services validate and normalize their inputs, even when called directly. Shared rules/messages are authoritative for HTTP and Filament; form validation may improve feedback but cannot replace service validation. Apply the same trimming/empty-string-to-null behavior as the HTTP path, without changing password/secret handling. Only validated editable fields reach persistence. Spanish field errors retain the existing field paths, including nested service/requirement paths.

Updates to resources, variants and event drafts require `expected_version` inside `$input`, checked under the row lock. Customers and characters currently have no version field: do not introduce one during this extraction. Resource kind remains immutable. Event updates remain draft-only.

Date inputs require ISO 8601 with an explicit UTC offset; persist UTC, display America/Chicago. Filament's adapter must serialize a selected local date with its intended offset and handle ambiguous DST times explicitly. Never guess an offset or silently reinterpret the event time. Money is integer cents; unknown travel fee stays null, not zero.

Validation raises Laravel `ValidationException` (HTTP 422); authorization uses `AuthorizationException` (403), and missing records use `ModelNotFoundException` (404). Existing business rejection statuses/messages remain unchanged: stale versions/committed edits and changed release reasons are 409; current validation/availability rejections that return 422 stay 422. Extract existing business aborts into a shared `App\Exceptions\RecordWriteRejected` carrying the existing status and Spanish message; the HTTP adapter preserves the response, and Filament catches it for a visible error without a success notification. No internal HTTP call, redirect or HTTP response belongs in a service.

## Actor, transactions and audit

Pass the signed-in User from the HTTP request or Filament session. Services reload the actor and require an active `owner_admin` with a secret and confirmed 2FA, using the same authorization policy as the API. This is defense in depth: login/challenge, session/CSRF and persistent Livewire authorization remain required at the entry points. A User object is not proof of a completed session challenge.

Use that explicit actor for audit `actor_id`, price `created_by` and block `released_by`; never rely on ambient `auth()` to choose a different actor. The supporting audit interface will be `Audit::recordFor(User $actor, string $action, Model $subject, array $before = [], array $after = []): void`. Keep the existing `Audit::record` compatibility path for initial admin provisioning and existing callers until separately migrated; do not invent an owner for a system action.

Each service owns its transaction. Business write and audit succeed or roll back together. Preserve current action names, before/after payloads, stable resource locks, sorted multi-resource locks, version increments and retry behavior. A resource block cannot interrupt a reservation or an already reviewed journey. Release locks the resource before its scoped block; identical retries return the same released record without another audit, and a different reason conflicts. Linking compatibility preserves the current pivot/upsert and audit behavior; unlinking is outside this interface.

Prices remain append-only. Draft saves atomically snapshot selected price/recipe/duration, reject retired variants and reserve no capacity; failure must restore the previous draft/services. Resource edits retain the future-commitment restriction. This extraction must not bypass `ConfirmBooking`, change its current signature, or implement cancellation, rescheduling, payment processing or automatic route estimates.

## Integration and acceptance

Controllers become adapters that pass the authenticated actor and input, then preserve existing HTTP status/payloads (including 201 on creation). Filament invokes the same services directly; it must override its default model-saving path and must not add a second save/audit or call Eloquent `create`, `update`, `save`, pivot writes or deletes for these operations. Supply the version originally displayed to the editor; do not replace it with a newly fetched version before saving. On failure retain the form values and show the error; a 409 requires reloading/reconciling before retrying.

Before writable screens are connected, verify the extraction on MariaDB with the existing suite and focused coverage for direct service validation/authorization, explicit audit actor without ambient auth, rollback on audit failure, stale versions, event snapshot rollback, occupied resources, block/travel conflicts and release retries. Retain the existing two-process reservation race. Exercise both adapters against the same rules and audit effects. These are implementation acceptance checks, not tests claimed as completed by this documentation change.
