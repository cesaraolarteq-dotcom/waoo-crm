<?php

namespace App\Filament\Support;

// MAIL-01 owner screens over Codex's outbox API (docs/MAIL-01-API.md). Spanish labels and the on/off rule only;
// every read and write goes through App\Services\Mail\*. "Enviado" never means "llegó": only a provider event says that.
final class MailUi
{
    public const TEMPLATES = ['balance_reminder' => 'Recordatorio de saldo', 'week_before' => 'Falta una semana', 'thank_you_review' => 'Gracias + reseña'];

    /** All nine client emails (ApprovedMail::KEYS); only the three reminders above can be planned today. */
    public const NAMES = ['inquiry_received' => 'Recibimos tu solicitud', 'quote_sent' => 'Tu cotización', 'details_request' => 'Termina tu reserva',
        'contract_ready' => 'Contrato listo', 'payment_received' => 'Pago recibido', 'zelle_notice_pending' => 'Aviso de Zelle recibido',
        ...self::TEMPLATES];

    public const STATUS = [
        'scheduled' => 'Programado', 'dispatching' => 'Enviándose (sin confirmar)', 'sent' => 'Enviado (no confirma que llegó)',
        'delivered' => 'Entregado', 'bounced' => 'Rebotó', 'complained' => 'Lo marcó como spam', 'blocked' => 'Bloqueado',
        'skipped' => 'Omitido', 'delivery_unknown' => 'No se sabe si salió',
    ];

    public const STATUS_COLOR = ['scheduled' => 'info', 'dispatching' => 'warning', 'sent' => 'success', 'delivered' => 'success', 'bounced' => 'danger',
        'complained' => 'danger', 'blocked' => 'warning', 'skipped' => 'gray', 'delivery_unknown' => 'danger'];

    public const ERRORS = [
        'recipient_not_allowed' => 'El correo del cliente no está en la lista de prueba.',
        'transport_unconfigured' => 'No hay proveedor de correo configurado.',
        'source_configuration_required' => 'Falta el texto aprobado o un dato del evento.',
        'source_changed_or_late' => 'El evento o el saldo cambió, o ya pasó el día del envío.',
        'recipient_suppressed' => 'Un correo anterior a esta dirección rebotó.',
        'payload_integrity_failed' => 'El contenido guardado no coincide; se bloqueó por seguridad.',
        'source_unavailable' => 'El evento o su enlace ya no cumplen las condiciones.',
        'transport_outcome_unknown' => 'No sabemos si salió: revísalo antes de pensar en reenviar.',
        'unsupported_environment_or_channel' => 'Canal o entorno no permitido.',
        'responsible_system_honeybook' => 'HoneyBook cobra y avisa esta fiesta: el CRM no le manda correos hasta el cambio final.',
    ];

    /** Server refusals of planning, in plain Spanish (codes from PlanEventMail / EventMailSource / ApprovedMail). */
    public const PLAN_ERRORS = [
        'responsible_system_honeybook' => 'HoneyBook cobra y avisa esta fiesta: el CRM no le programa correos hasta el cambio final.',
        'mail_source_changed' => 'El evento o la ficha del cliente cambió. Recarga la página.',
        'mail_link_expires_before_reminder' => 'El enlace vence antes de algún recordatorio. Crea un enlace que dure más.',
        'mail_event_requires_current_confirmed_finance' => 'El evento necesita recursos apartados con su pago vinculado y montos al día.',
        'mail_link_inactive' => 'Ese enlace ya no sirve (vencido, anulado o de otro cliente).',
        'payment_link_scope_required' => 'Para el recordatorio de saldo, el enlace debe permitir pagar.',
        'recipient_required' => 'El cliente no tiene un correo válido.',
        'missing_recipient_locale' => 'El cliente no tiene idioma (español o inglés).',
        'approved_template_required' => 'Falta el texto aprobado de ese correo (César lo aprueba antes de activar).',
        'invalid_approved_template' => 'El texto aprobado configurado no es válido.',
        'unapproved_review_link' => 'Falta configurar el enlace de reseñas de Google.',
        'invalid_message_link' => 'El enlace del correo no es válido.',
        'invalid_subject' => 'El asunto aprobado es demasiado largo o no es válido.',
        'missing_or_unknown_template_variable' => 'El texto aprobado usa un dato que no existe o falta.',
        'missing_template_variable' => 'Falta un dato que el texto aprobado necesita.',
        'unknown_template_variable' => 'El texto aprobado usa un dato desconocido.',
    ];

    public const SKIPPED = ['past_due' => 'ya pasó su fecha', 'no_balance' => 'no hay saldo pendiente'];

    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing', 'staging']) && (bool) config('waoo.sandbox_mail', false)
            && class_exists(\App\Services\Mail\PlanEventMail::class);
    }

    /** First human sentence for a refusal: known code → Spanish, otherwise the server's own message. */
    public static function refusal(\Throwable $e): string
    {
        $raw = $e instanceof \Illuminate\Validation\ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
        foreach (self::PLAN_ERRORS as $code => $text) {
            if (str_contains($raw, $code)) {
                return $text;
            }
        }

        return $raw ?: 'No se pudo guardar.';
    }
}
