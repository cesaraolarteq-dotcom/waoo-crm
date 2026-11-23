<?php
namespace App\Support;

use App\Models\{MessageSetting, User};
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Mail\ApprovedMail;

/**
 * Owner 2026-11-23 "Mensajes automáticos": the automatic client messages in the order a client receives them, the
 * owner's on/off per channel, and the approval of texts proposed on the page. A message leaves only when its text is
 * approved AND its switch is on; test mode (WAOO_MAIL_TEST_RECIPIENTS / WAOO_SMS_ALLOWLIST) still limits who receives it.
 */
final class AutoMessages
{
    /** Client journey order. 'sms' = a text version exists for this message. */
    public const FLOW = [
        'inquiry_received' => ['name' => 'Recibimos tu solicitud', 'when' => 'Al instante, cuando llega una solicitud desde la web', 'sms' => false],
        'payment_received' => ['name' => 'Pago recibido', 'when' => 'Al instante, cuando registras un pago (Zelle verificado o tarjeta)', 'sms' => true],
        'contract_signed' => ['name' => 'Contrato firmado', 'when' => 'Al instante, cuando el cliente firma su contrato', 'sms' => true],
        'balance_reminder' => ['name' => 'Recordatorio de saldo', 'when' => 'Un día antes de que venza el saldo (solo si hay saldo)', 'sms' => true],
        'week_before' => ['name' => 'Falta una semana', 'when' => '7 días antes de la fiesta', 'sms' => true],
        'thank_you_review' => ['name' => 'Gracias + reseña', 'when' => 'El día después de la fiesta, a las 10 a. m.', 'sms' => true],
    ];

    /** Emails whose text lives in config/auto-messages.php and is approved on the page (the others are in client-texts). */
    public const PAGE_APPROVED_EMAILS = ['contract_signed'];

    public static function keys(): array { return array_keys(self::FLOW); }

    public static function known(string $key): bool { return isset(self::FLOW[$key]); }

    public static function setting(string $key): MessageSetting
    {
        return MessageSetting::firstOrNew(['key' => $key], ['email_enabled' => true, 'sms_enabled' => false, 'version' => 1]);
    }

    public static function emailSha(string $key): ?string
    {
        $text = in_array($key, self::PAGE_APPROVED_EMAILS, true) ? config('auto-messages.contract_signed_email') : config('waoo.mail_templates.'.$key);
        return is_array($text) ? MutationKey::hash($text) : null;
    }

    public static function smsSha(string $key): ?string
    {
        $text = config('auto-messages.sms.'.$key);
        return (self::FLOW[$key]['sms'] ?? false) && is_array($text) ? MutationKey::hash($text) : null;
    }

    public static function emailApproved(string $key, ?MessageSetting $s = null): bool
    {
        if (! self::known($key)) return false;
        if (! in_array($key, self::PAGE_APPROVED_EMAILS, true)) {
            try { ApprovedMail::definition($key, 'es'); ApprovedMail::definition($key, 'en'); return true; } catch (\Throwable) { return false; }
        }
        $sha = self::emailSha($key); $s ??= self::setting($key);
        return $sha !== null && is_string($s->email_approved_sha256) && hash_equals($s->email_approved_sha256, $sha);
    }

    public static function smsApproved(string $key, ?MessageSetting $s = null): bool
    {
        $sha = self::smsSha($key); $s ??= self::setting($key);
        return $sha !== null && is_string($s->sms_approved_sha256) && hash_equals($s->sms_approved_sha256, $sha);
    }

    /** Messages outside the catalog (anniversary, reschedule…) are not controlled here and stay as they were. */
    public static function emailOn(string $key): bool
    {
        if (! self::known($key)) return true;
        $s = self::setting($key);
        return $s->email_enabled && self::emailApproved($key, $s);
    }

    public static function smsOn(string $key): bool
    {
        if (! self::known($key)) return false;
        $s = self::setting($key);
        return $s->sms_enabled && self::smsApproved($key, $s);
    }

    /** Flip one switch. Refuses a switch whose text is not approved. */
    public static function toggle(User $actor, string $key, string $channel): bool
    {
        abort_unless(self::known($key) && in_array($channel, ['email', 'sms'], true), 404);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($actor, $key, $channel) {
            $s = MessageSetting::where('key', $key)->lockForUpdate()->first() ?? self::setting($key);
            $approved = $channel === 'email' ? self::emailApproved($key, $s) : self::smsApproved($key, $s);
            if (! $approved) throw \Illuminate\Validation\ValidationException::withMessages(['message' => 'Primero aprueba el texto.']);
            $field = $channel.'_enabled'; $new = ! $s->{$field};
            $s->fill([$field => $new, 'updated_by' => $actor->id, 'version' => ($s->version ?? 0) + 1])->save();
            \App\Services\Audit::recordFor($actor, 'messages.toggled', $s, [], ['key' => $key, 'channel' => $channel, 'enabled' => $new]);
            return $new;
        });
    }

    /** Owner approves the exact current text of one proposed message (stored as a hash, see config/auto-messages.php). */
    public static function approve(User $actor, string $key, string $channel): void
    {
        abort_unless(self::known($key) && in_array($channel, ['email', 'sms'], true), 404);
        $sha = $channel === 'email' ? (in_array($key, self::PAGE_APPROVED_EMAILS, true) ? self::emailSha($key) : null) : self::smsSha($key);
        abort_if($sha === null, 404);
        \Illuminate\Support\Facades\DB::transaction(function () use ($actor, $key, $channel, $sha) {
            $s = MessageSetting::where('key', $key)->lockForUpdate()->first() ?? self::setting($key);
            $s->fill([$channel.'_approved_sha256' => $sha, 'updated_by' => $actor->id, 'version' => ($s->version ?? 0) + 1])->save();
            \App\Services\Audit::recordFor($actor, 'messages.text_approved', $s, [], ['key' => $key, 'channel' => $channel, 'sha256' => $sha]);
        });
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function renderEmail(string $key, string $locale, #[\SensitiveParameter] array $vars, array $totals = []): array
    {
        $locale = $locale === 'en' ? 'en' : 'es';
        if (! in_array($key, self::PAGE_APPROVED_EMAILS, true)) {
            $r = ApprovedMail::render($key, $locale, array_intersect_key($vars, array_flip(ApprovedMail::VARIABLES)), $totals);
            return ['subject' => $r['subject'], 'html' => $r['html'], 'text' => $r['text']];
        }
        $d = config('auto-messages.contract_signed_email.'.$locale);
        foreach (['first_name', 'event_date', 'paid', 'balance', 'link'] as $need) if (blank($vars[$need] ?? null)) ApprovedMail::fail('missing_template_variable');
        ApprovedMail::url($vars['link'], true);
        $fill = fn (string $s) => preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $s);
        $mail = ['subject' => $fill($d['subject']), 'paragraphs' => array_map($fill, $d['paragraphs']), 'closing' => $d['closing'],
            'button' => ['label' => $d['button'], 'url' => $vars['link']], 'button2' => null];
        $text = implode("\n\n", [...$mail['paragraphs'], $d['button'].': '.$vars['link'], $d['closing'], '— Waoo Party']);
        $html = view('mail.layout', ['lang' => $locale, 'mail' => $mail, 'totals' => [], 'logo' => Sandbox::origin().'/img/waoo-logo.png'])->render();
        return ['subject' => $mail['subject'], 'html' => $html, 'text' => $text];
    }

    public static function renderSms(string $key, string $locale, #[\SensitiveParameter] array $vars): string
    {
        $text = config('auto-messages.sms.'.$key.'.'.($locale === 'en' ? 'en' : 'es'));
        if (! is_string($text)) ApprovedMail::fail('approved_template_required');
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? null;
            if ($v === null || $v === '') ApprovedMail::fail('missing_template_variable');
            return (string) $v;
        }, $text);
    }

    /** Fictitious data for previews and owner test sends (María González, a Saturday party in Katy). */
    public static function sample(string $lang): array
    {
        $es = $lang !== 'en'; $origin = Sandbox::origin();
        return ['first_name' => 'María', 'event_date' => $es ? 'sábado 12 de diciembre de 2026, 3:00 pm' : 'Sat, Dec 12, 2026 3:00 PM',
            'subject_date' => $es ? 'sábado 12 de diciembre' : 'Sat, Dec 12', 'address' => '123 Sample St, Katy, TX 77494', 'zip' => '77494',
            'package' => $es ? 'Música y Juegos 2.0' : 'Music & Games 2.0', 'package_price' => '$600.00', 'travel_fee' => '$40.00', 'subtotal' => '$640.00',
            'discount_total' => '$0.00', 'total' => '$640.00', 'deposit' => '$320.00', 'amount' => '$320.00', 'paid' => '$320.00', 'balance' => '$320.00',
            'has_balance' => true, 'due' => $es ? '11 de diciembre de 2026' : 'Dec 11, 2026',
            'balance_due' => $es ? ' · vence viernes 11 de diciembre de 2026, 3:00 pm' : ' · due Fri, Dec 11, 2026 3:00 PM',
            'valid_until' => $es ? '30 de noviembre de 2026' : 'Nov 30, 2026', 'link' => $origin.'/portal#token=ejemplo',
            'review_link' => (string) config('waoo.mail_review_url'), 'tip_link' => $origin.'/portal/tip#ejemplo'];
    }

    public static function sampleTotals(string $lang): array
    {
        $es = $lang !== 'en';
        return [['label' => $es ? 'Paquete' : 'Package', 'amount' => '$600.00'], ['label' => $es ? 'Costo de transporte' : 'Travel fee', 'amount' => '$40.00'],
            ['label' => 'Total', 'amount' => '$640.00', 'strong' => true]];
    }
}
