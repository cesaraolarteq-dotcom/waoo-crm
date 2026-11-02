<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverFinancialBinding, CutoverTransfer, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use Illuminate\Support\Facades\DB;

/**
 * C3 B4 reversal ("Deshacer el cambio"): the whole group goes back to HoneyBook only while the CRM has done nothing for any
 * member since the effective time: no money row, receipt allocation, payment attempt, Zelle notice, tip, email/SMS (planned,
 * sent or unknown) or unrevoked client link. Unknown is never treated as "not sent / not paid". Partial reversal is refused.
 * The transfer, bindings, opening and financial rows stay as history; only the active slots and responsibility change.
 */
final class ReverseCutoverTransfer
{
    public function execute(User $actor, array $input, string $key): array
    {
        CutoverAccess::actor($actor); Sandbox::require('sandbox_cutover_bridge'); Sandbox::require('sandbox_cutover_transfer');
        $d = ImportInput::input($input, ['transfer_id' => 'required|integer|min:1', 'expected_transfer_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'reason' => 'required|string|min:5|max:500']);
        $d = ['transfer_id' => (int) $d['transfer_id'], 'expected_transfer_sha256' => $d['expected_transfer_sha256'], 'reason' => trim($d['reason'])];
        $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':cutover:reversal';
        return app(CutoverCohort::class)->run($actor, function ($actor, $links, $events) use ($d, $hash, $key, $scope) {
            Sandbox::require('sandbox_cutover_transfer');
            $transfer = CutoverTransfer::whereKey($d['transfer_id'])->lockForUpdate()->firstOrFail();
            if (MutationKey::find($scope, $key, $hash, true)) return ActivateCutoverTransfer::result($transfer);
            RecordWriteRejected::unless(hash_equals($transfer->payload_sha256, $d['expected_transfer_sha256']), 409, 'El cambio en pantalla no es el vigente. Recarga.');
            RecordWriteRejected::unless(!DB::table('cutover_reversals')->where('cutover_transfer_id', $transfer->id)->lockForUpdate()->exists(), 409, 'Este cambio ya se deshizo.');
            $bindings = CutoverFinancialBinding::where('cutover_transfer_id', $transfer->id)->orderBy('event_id')->lockForUpdate()->get();
            $ids = $bindings->pluck('event_id')->all();
            $slots = DB::table('cutover_active_openings')->whereIn('event_id', $ids)->orderBy('event_id')->lockForUpdate()->get();
            RecordWriteRejected::unless($ids !== [] && $slots->count() === count($ids) && $slots->pluck('cutover_financial_binding_id')->sort()->values()->all() === $bindings->pluck('id')->sort()->values()->all()
                && count($ids) === count($transfer->payload['members']), 409, 'El grupo activo no coincide con el cambio. No se puede deshacer por partes.');
            foreach ($ids as $id) RecordWriteRejected::unless(($events->get($id)?->responsible_system) === 'crm', 409, 'Una fiesta ya no está a cargo del CRM. Requiere revisión.');
            $blockers = self::blockers($ids, $transfer->effective_at, $bindings->pluck('id')->all());
            RecordWriteRejected::unless($blockers === [], 409, 'No se puede deshacer: el CRM ya hizo algo con estas fiestas ('.implode(', ', $blockers).'). Revísalas una por una.');
            DB::table('cutover_active_openings')->whereIn('event_id', $ids)->delete();
            DB::table('events')->whereIn('id', $ids)->update(['responsible_system' => 'honeybook']);
            $payload = ['schema_version' => 1, 'transfer_id' => $transfer->id, 'transfer_sha256' => $transfer->payload_sha256, 'event_ids' => $ids, 'reason' => $d['reason'], 'created_by' => $actor->id];
            $id = DB::table('cutover_reversals')->insertGetId(['cutover_transfer_id' => $transfer->id, 'reason' => $d['reason'], 'payload' => encrypt($payload),
                'payload_sha256' => MutationKey::hash($payload), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($ids as $eventId) Audit::recordFor($actor, 'cutover.event_returned', $events->get($eventId), ['responsible_system' => 'crm'], ['responsible_system' => 'honeybook', 'transfer_id' => $transfer->id]);
            Audit::recordFor($actor, 'cutover.transfer_reversed', $transfer, [], ['reversal_id' => $id, 'event_count' => count($ids)]);
            MutationKey::store($scope, $key, $hash, 'cutover_transfer', $transfer->id);
            return ActivateCutoverTransfer::result($transfer);
        });
    }

    /** @return list<string> codes of what the CRM already did for any member since the switch */
    public static function blockers(array $eventIds, $since, array $bindingIds): array
    {
        $found = [];
        $any = fn (string $table, string $column = 'created_at') => DB::table($table)->whereIn('event_id', $eventIds)->where($column, '>=', $since)->exists();
        if ($any('ledger_entries')) $found[] = 'pagos';
        if (DB::table('opening_receipt_allocations')->whereIn('cutover_financial_binding_id', $bindingIds)->exists()) $found[] = 'pagos asignados';
        if (DB::table('payment_intents')->whereIn('event_id', $eventIds)->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhereNotIn('status', ['expired', 'failed', 'cancelled', 'paid']))->exists()) $found[] = 'intentos de pago';
        if ($any('zelle_notices')) $found[] = 'avisos de Zelle';
        if ($any('tip_grants') || $any('tip_checkout_intents')) $found[] = 'propinas';
        if ($any('outbound_messages') || $any('mail_occurrences')) $found[] = 'correos';
        if ($any('sms_messages')) $found[] = 'textos';
        if (DB::table('portal_grants')->whereIn('event_id', $eventIds)->where('created_at', '>=', $since)->whereNull('revoked_at')->exists()) $found[] = 'enlaces al cliente';
        return $found;
    }
}
