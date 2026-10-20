<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverFinancialBinding, CutoverOpeningSet, CutoverTransfer, Event, FinancialRevision, ImportedProjectRevision};
use App\Services\Imports\{HoneyBookFutureSource, OpeningSetIntegrity};
use Illuminate\Support\Facades\DB;

/** Validates future activation evidence; no method here creates or activates it. */
final class EffectiveOpeningAgreement
{
    public static function find(Event $event): ?array
    {
        OpeningReceiptAccess::gates();
        $slot = DB::table('cutover_active_openings')->where('event_id', $event->id)->sharedLock()->first();
        if (!$slot) return null;
        try {
            $binding = CutoverFinancialBinding::whereKey($slot->cutover_financial_binding_id)->sharedLock()->firstOrFail();
            $transfer = CutoverTransfer::whereKey($binding->cutover_transfer_id)->sharedLock()->firstOrFail(); $t = $transfer->payload;
            $set = CutoverOpeningSet::whereKey($transfer->cutover_opening_set_id)->sharedLock()->firstOrFail(); $saved = OpeningSetIntegrity::load($set);
            self::check(is_array($t) && ($t['schema_version'] ?? null) === 1 && $t['opening_set_id'] === $set->id
                && $t['created_by'] === $transfer->created_by && $t['effective_at'] === $transfer->effective_at->toIso8601String()
                && $t['opening_set_sha256'] === $set->payload_sha256 && hash_equals($transfer->payload_sha256, MutationKey::hash($t))
                && !$transfer->effective_at->isFuture() && $transfer->effective_at->greaterThanOrEqualTo($set->source_observed_at)
                && $transfer->effective_at->greaterThanOrEqualTo($set->created_at) && $transfer->effective_at->lessThan($set->expires_at));
            $members = CutoverFinancialBinding::where('cutover_transfer_id', $transfer->id)->orderBy('event_id')->limit(2001)->sharedLock()->get();
            $actualKeys = $members->pluck('event_id')->sort()->values()->all(); $expectedKeys = array_map('intval', array_keys($saved['facts'])); sort($expectedKeys);
            self::check($actualKeys === $expectedKeys && array_keys($t['members']) === array_keys($saved['facts']));
            $slots = DB::table('cutover_active_openings')->whereIn('cutover_financial_binding_id', $members->pluck('id'))->orderBy('event_id')->limit(2001)->sharedLock()->get();
            self::check($slots->count() === $members->count());
            $selected = null;
            foreach ($members as $member) {
                $p = $member->payload; $f = $p['facts']; $o = $saved['facts'][(string)$member->event_id];
                $finance = FinancialRevision::whereKey($member->financial_revision_id)->sharedLock()->firstOrFail();
                $source = ImportedProjectRevision::whereKey($o['source_revision_id'])->sharedLock()->firstOrFail(); $s = $source->source_snapshot;
                self::check($source->source_sha256 === $o['source_sha256'] && MutationKey::hash($s) === $o['source_sha256']);
                $expectedLines = [['source' => 'honeybook', 'source_revision_id' => $source->id, 'name' => $s['services'], 'characters_text' => $s['characters']]];
                $slotRow = $slots->firstWhere('event_id', $member->event_id);
                self::check($p['transfer_id'] === $transfer->id && $member->cutover_transfer_id === $transfer->id
                    && $member->event_id === $f['event_id'] && $member->cutover_opening_agreement_id === $f['opening_id']
                    && $member->financial_revision_id === $f['financial_revision_id'] && $slotRow?->cutover_financial_binding_id === $member->id
                    && $f['opening_id'] === $saved['rows']->firstWhere('event_id', $member->event_id)?->id
                    && $f['opening_sha256'] === MutationKey::hash($o) && ($t['members'][(string)$member->event_id] ?? null) === MutationKey::hash($f)
                    && hash_equals($member->payload_sha256, MutationKey::hash($p)) && $f['financial_sha256'] === MutationKey::hash($finance->toArray())
                    && $transfer->effective_at->greaterThanOrEqualTo($finance->created_at)
                    && $finance->event_id === $member->event_id && $finance->event_version === $o['event_version'] && $finance->currency === 'USD'
                    && $finance->total_cents === $o['original_total_cents'] && $finance->deposit_target_cents === $o['deposit_target_cents']
                    && $finance->subtotal_cents === HoneyBookFutureSource::cents($s['package_total']) && $finance->travel_fee_cents === HoneyBookFutureSource::cents($s['travel_fee'])
                    && $finance->discount_cents === HoneyBookFutureSource::cents($s['discount']) && $finance->tax_cents === 0 && $finance->customer_fee_cents === 0
                    && MutationKey::hash($finance->lines_snapshot) === MutationKey::hash($expectedLines)
                    && $finance->balance_due_at?->toIso8601String() === $o['crm_balance_due_at']);
                if ($member->id === $binding->id) $selected = ['transfer' => $transfer, 'binding' => $member, 'finance' => $finance, 'opening' => $o];
            }
            self::check($selected !== null && $binding->event_id === $event->id);
            return [...$selected, ...OpeningCollectionBinding::inspect($event, $selected)];
        } catch (RecordWriteRejected|\Illuminate\Database\QueryException $e) { throw $e; }
        catch (\Throwable) { throw new RecordWriteRejected(409, 'La autoridad financiera del acuerdo requiere revisión.'); }
    }
    private static function check(bool $valid): void { RecordWriteRejected::unless($valid, 409, 'La autoridad financiera del acuerdo requiere revisión.'); }
}
