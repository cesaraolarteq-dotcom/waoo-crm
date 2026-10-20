<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, ImportedProjectLink};

final class OpeningReceiptContext
{
    public static function receipt(Event $event, array $input): ?array
    {
        $extra = ['opening_id', 'expected_collection_sha256', 'post_cutover_received'];
        if (!ImportedProjectLink::where('event_id', $event->id)->sharedLock()->first()) {
            RecordWriteRejected::unless(array_intersect(array_keys($input), $extra) === [], 422, 'Esta fiesta no usa un acuerdo importado.'); return null;
        }
        $c = self::current($event);
        RecordWriteRejected::unless((int)($input['opening_id'] ?? 0) === $c['binding']->cutover_opening_agreement_id
            && hash_equals($c['collection_sha256'], $input['expected_collection_sha256'] ?? '')
            && in_array($input['post_cutover_received'] ?? null, [true, 1, '1', 'yes', 'on'], true)
            && (int)($input['financial_revision_id'] ?? 0) === $c['finance']->id
            && \Carbon\CarbonImmutable::parse($input['received_at'])->greaterThanOrEqualTo($c['transfer']->effective_at),
            409, 'Revisa el acuerdo y confirma que este dinero se recibió después del cambio, sin repetir pagos de HoneyBook.');
        return $c;
    }
    public static function current(Event $e): array
    {
        \App\Services\Responsibility\RequireCrmResponsibility::check($e);
        $c = EffectiveOpeningAgreement::find($e);
        RecordWriteRejected::unless($c !== null && $c['collection_current'], 409, 'Este acuerdo importado aún no autoriza registrar pagos del CRM. Requiere revisión.');
        return $c;
    }
}
