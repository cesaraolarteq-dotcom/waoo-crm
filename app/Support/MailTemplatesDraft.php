<?php
namespace App\Support;

// Kept as a compatibility name for existing preview routes. All seven texts are owner-approved.
final class MailTemplatesDraft
{
    public const KEYS = \App\Services\Mail\ApprovedMail::KEYS;
    public const WHEN = [
        'inquiry_received' => 'Cuando llega una solicitud desde la web',
        'quote_sent' => 'Cuando envías una cotización',
        'details_request' => 'Después del depósito, para completar datos y firmar',
        'payment_received' => 'Cuando confirmas un pago (Zelle verificado o tarjeta)',
        'balance_reminder' => 'Un día antes de que venza el saldo (solo si hay saldo)',
        'week_before' => '7 días antes de la fiesta',
        'thank_you_review' => 'El día después de la fiesta, a las 10 a. m.',
    ];
    // Full HTML of one draft with fictitious sample data (preview only).
    public static function preview(string $key, string $lang): string
    {
        $es = $lang === 'es';
        $vars = ['first_name' => 'Ana', 'event_date' => $es ? 'sábado 21 de noviembre a las 3:00 pm' : 'Sat, Nov 21 at 3:00 pm', 'subject_date' => $es ? 'sábado 21 de noviembre' : 'Sat, Nov 21', 'zip' => '77493', 'address' => '123 Sample St, Katy, TX', 'package' => $es ? 'Música y Juegos 2.0' : 'Music & Games 2.0',
            'deposit' => '$307.50', 'paid' => '$307.50', 'amount' => '$307.50', 'balance' => '$307.50', 'balance_due' => $es ? ' (vence el viernes 20 de noviembre)' : ' (due Fri, Nov 20)', 'due' => $es ? 'viernes 20 de noviembre' : 'Fri, Nov 20',
            'valid_until' => $es ? 'viernes 30 de octubre' : 'Fri, Oct 30', 'link' => url('/portal/ejemplo'), 'review_link' => 'https://g.page/r/sample', 'tip_link' => url('/dev/portal/tip?lang='.$lang)];
        $totals = [['label' => $es ? 'Paquete' : 'Package', 'amount' => '$600'], ['label' => $es ? 'Costo de transporte' : 'Travel fee', 'amount' => '$40'],
            ['label' => $es ? 'Descuento por reservar hoy' : 'Book-today discount', 'amount' => '−$25', 'discount' => true], ['label' => 'Total', 'amount' => '$615', 'strong' => true]];

        return view('mail.layout', ['lang' => $lang, 'mail' => self::render($key, $lang, $vars), 'totals' => $totals, 'logo' => url('/img/waoo-logo.png')])->render();
    }

    public static function render(string $key, string $lang, array $vars): array
    {
        abort_unless(in_array($key, self::KEYS, true), 404);
        $d = ApprovedClientTexts::mailDefinitions(config('client-texts'))[$key][$lang === 'en' ? 'en' : 'es'];
        $fill = fn (string $s) => preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $s);
        $subject = str_replace('{first_name}', mb_substr((string) ($vars['first_name'] ?? ''), 0, 20), $d['subject']);
        $buttons = [];
        foreach (['button', 'button2'] as $slot) $buttons[$slot] = ($b = $d[$slot]) ? ['label' => $b['label'], 'url' => (string) ($vars[$b['variable']] ?? '')] : null;
        return ['subject' => $fill($subject), 'paragraphs' => array_map($fill, $d['paragraphs']), 'closing' => $fill($d['closing']), ...$buttons];
    }
}
