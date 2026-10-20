<?php

namespace App\Filament\Support;

// C3 A owner screens (docs/CUTOVER-EVIDENCE-API.md): evidence only. Transfer is never available here.
final class CutoverUi
{
    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing', 'staging']) && config('waoo.sandbox_cutover') === true && config('waoo.sandbox_imports') === true
            && class_exists(\App\Services\Imports\ReadHoneyBookCutoverReadiness::class);
    }

    public const MISSING = [
        'cohort_empty' => 'No hay fiestas importadas de HoneyBook.',
        'cohort_changed' => 'El grupo de fiestas cambió: vuelve a revisar.',
        'source_integrity_invalid' => 'Los importes guardados no cuadran: revisión manual.',
        'capacity_review_required' => 'Falta apartar staff y trajes (o algo apartado ya no está activo).',
        'responsibility_changed' => 'Esta fiesta ya no la cobra HoneyBook.',
        'original_document_required' => 'Falta subir el contrato firmado original (PDF).',
        'document_integrity_invalid' => 'El PDF guardado no pasó la verificación.',
        'owner_review_required' => 'Falta tu revisión de esta fiesta.',
        'review_stale' => 'Algo cambió desde tu revisión: revísala otra vez.',
        'source_reconciliation_stale' => 'Tu revisión de cuentas tiene más de 4 horas.',
        'source_due_time_review_required' => 'Falta la fecha y hora en que vence el saldo.',
        'external_collection_not_retired' => 'Falta confirmar que quitaste los cobros de HoneyBook.',
        'honeybook_automations_active' => 'Falta confirmar que apagaste los avisos automáticos de HoneyBook.',
        'crm_collection_in_flight' => 'Hay un cobro del CRM en curso.',
        'crm_money_requires_reconciliation' => 'Hay dinero del CRM por conciliar.',
        'tip_collection_or_reconciliation_open' => 'Hay una propina en curso o por conciliar.',
        'message_outcome_unknown' => 'Hay un mensaje con resultado desconocido.',
        'freshness_policy_required' => 'Falta configurar la regla de 4 horas.',
        'source_scope_incomplete' => 'Hay fiestas del archivo de HoneyBook sin importar.',
        'source_scope_unresolved' => 'Hay filas del archivo de HoneyBook sin resolver.',
        'active_client_portal_link' => 'La clienta todavía tiene un enlace activo del portal.',
        'reconciliation_required' => 'Falta preparar la evidencia del grupo completo.',
        'reconciliation_integrity_invalid' => 'La evidencia guardada no pasó la verificación.',
        'financial_bridge_not_implemented' => 'El paso del cobro al CRM todavía no está programado.',
        'transfer_not_implemented' => 'El botón para pasar al CRM todavía no está disponible.',
    ];

    public static function label(string $code): string
    {
        return self::MISSING[$code] ?? $code;
    }
}
