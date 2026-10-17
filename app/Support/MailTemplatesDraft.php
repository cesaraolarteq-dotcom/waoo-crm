<?php

namespace App\Support;

// MAIL-01 v1 DRAFT copy (docs/templates/email/MAIL-01-templates-v1.md), for the design preview only.
// Not approved by the owner, not used for sending. Each template: subject, paragraphs, optional button (label → link variable), closing line.
final class MailTemplatesDraft
{
    public const KEYS = ['inquiry_received', 'quote_sent', 'details_request', 'contract_ready', 'payment_received', 'zelle_notice_pending', 'balance_reminder', 'week_before', 'thank_you_review'];

    private const T = [
        'inquiry_received' => ['es' => ['¡Recibimos tu solicitud, {first_name}!', ['¡Hola {first_name}! Gracias por escribir a Waoo Party. Recibimos tu solicitud para el {event_date} y ya estamos revisando la disponibilidad para tu código postal.', 'Muy pronto te enviamos opciones y el precio completo, con el costo de transporte incluido. Si prefieres hablar antes, escríbenos o llámanos al (346) 851-8830.'], null, '¡Estamos emocionados de ser parte de tu celebración!'],
                               'en' => ['We got your request, {first_name}!', ["Hi {first_name}! Thank you for contacting Waoo Party. We received your request for {event_date} and we're checking availability for your ZIP code now.", "We'll reply very soon with options and the complete price, including the travel fee. If you'd like to talk sooner, text or call us at (346) 851-8830."], null, "We're excited to be part of your celebration!"]],
        'quote_sent' => ['es' => ['Tu cotización de Waoo Party para el {event_date}', ['¡Hola {first_name}! Aquí está tu cotización de {package} para el {event_date}.', '__totals__', 'Para apartar tu fecha pedimos un depósito del 50 % ({deposit}). Esta cotización es válida hasta el {valid_until}.'], ['Ver y aceptar mi cotización', 'link'], '¡Estamos emocionados de ser parte de tu celebración!'],
                         'en' => ['Your Waoo Party quote for {event_date}', ['Hi {first_name}! Here is your quote for {package} on {event_date}.', '__totals__', 'To reserve your date we need a 50% deposit ({deposit}). This quote is valid until {valid_until}.'], ['Review and accept my quote', 'link'], "We're excited to be part of your celebration!"]],
        'details_request' => ['es' => ['Termina tu reserva, {first_name}', ['¡Hola {first_name}! Gracias por tu depósito de {paid}: registramos tu solicitud para el {event_date}.', 'Para terminar tu reserva, completa tus datos y firma tu contrato. Solo toma unos minutos.'], ['Completar mis datos', 'link'], '¡Estamos emocionados de ser parte de tu celebración!'],
                              'en' => ['Finish your booking, {first_name}', ['Hi {first_name}! Thank you for your deposit of {paid} — your request for {event_date} is recorded.', 'To finish your booking, please complete your details and sign your contract. It only takes a few minutes.'], ['Complete my details', 'link'], "We're excited to be part of your celebration!"]],
        'contract_ready' => ['es' => ['Tu contrato está listo para firmar', ['¡Hola {first_name}! Tu contrato de Waoo Party para el {event_date} está listo.', 'Ahí también ves tu saldo ({balance}, vence el {due}) y cómo pagarlo.'], ['Leer y firmar', 'link'], '¡Estamos emocionados de ser parte de tu celebración!'],
                             'en' => ['Your contract is ready to sign', ['Hi {first_name}! Your Waoo Party contract for {event_date} is ready.', 'There you can also see your balance ({balance}, due {due}) and how to pay.'], ['Read and sign', 'link'], "We're excited to be part of your celebration!"]],
        'payment_received' => ['es' => ['Pago recibido, ¡gracias!', ['¡Muchas gracias, {first_name}! Recibimos tu pago de {amount}.', 'Pagado hasta hoy: {paid} · Saldo: {balance}{balance_due}.'], null, '¡Estamos emocionados de ser parte de tu celebración!'],
                               'en' => ['Payment received — thank you!', ['Thanks so much, {first_name}! We received your payment of {amount}.', 'Paid so far: {paid} · Balance: {balance}{balance_due}.'], null, "We're excited to be part of your celebration!"]],
        'zelle_notice_pending' => ['es' => ['Recibimos tu aviso de Zelle', ['¡Hola {first_name}! Gracias por avisarnos que enviaste {amount} por Zelle.', 'En cuanto lo veamos en el banco te lo confirmamos. Mientras tanto tu saldo no cambia.'], null, null],
                                   'en' => ['We got your Zelle notice', ['Hi {first_name}! Thanks for letting us know you sent {amount} by Zelle.', "As soon as we see it in our bank we'll confirm it. Until then your balance stays the same."], null, null]],
        'balance_reminder' => ['es' => ['Recordatorio: tu saldo vence el {due}', ['¡Hola {first_name}! Ya se acerca tu fiesta del {event_date}. Tu saldo de {balance} vence el {due}.', 'Puedes pagar por Zelle al 346-216-8739 (Waoo Party LLC, sin cargo) o con tarjeta.'], ['Pagar mi saldo', 'link'], '¡Estamos emocionados de ser parte de tu celebración!'],
                               'en' => ['Friendly reminder: balance due {due}', ['Hi {first_name}! Your party is coming up on {event_date}. Your balance of {balance} is due {due}.', 'You can pay by Zelle to 346-216-8739 (Waoo Party LLC, no fee) or by card.'], ['Pay my balance', 'link'], "We're excited to be part of your celebration!"]],
        'week_before' => ['es' => ['¡Falta una semana, {first_name}!', ['¡Hola {first_name}! Falta una semana para tu celebración con Waoo Party: {event_date} en {address}.', 'Revisa que la dirección, la hora y los detalles estén bien. Si algo cambió, respóndenos o escríbenos al (346) 851-8830.'], ['Revisar mi fiesta', 'link'], '¡Nos vemos pronto!'],
                          'en' => ['One week to go, {first_name}!', ['Hi {first_name}! Your Waoo Party celebration is one week away: {event_date} at {address}.', 'Please check that the address, start time and details are right. If anything changed, reply or text us at (346) 851-8830.'], ['Check my party', 'link'], 'See you soon!']],
        'thank_you_review' => ['es' => ['¡Gracias por celebrar con nosotros!', ['¡Hola {first_name}! Gracias por elegir Waoo Party. ¡Esperamos que todos la hayan pasado increíble!', 'Si tienes un minuto, una reseña en Google ayuda a otras familias a encontrarnos.'], ['Dejar una reseña', 'review_link'], '¡Nos encantaría celebrar contigo otra vez!'],
                               'en' => ['Thank you for celebrating with us!', ['Hi {first_name}! Thank you for choosing Waoo Party. We hope everyone had an amazing time!', 'If you have a minute, a Google review helps other families find us.'], ['Leave a review', 'review_link'], "We'd love to celebrate with you again!"]],
    ];

    // When each one goes out (for the owner's settings page).
    public const WHEN = [
        'inquiry_received' => 'Cuando llega una solicitud desde la web',
        'quote_sent' => 'Cuando envías una cotización',
        'details_request' => 'Después del depósito, para completar datos y firmar',
        'contract_ready' => 'Cuando el contrato está listo para firmar',
        'payment_received' => 'Cuando confirmas un pago (Zelle verificado o tarjeta)',
        'zelle_notice_pending' => 'Cuando la clienta avisa que mandó un Zelle',
        'balance_reminder' => 'Un día antes de que venza el saldo (solo si hay saldo)',
        'week_before' => '7 días antes de la fiesta',
        'thank_you_review' => 'El día después de la fiesta',
    ];

    // Full HTML of one draft with fictitious sample data (preview only).
    public static function preview(string $key, string $lang): string
    {
        $es = $lang === 'es';
        $vars = ['first_name' => 'Ana', 'event_date' => $es ? 'sábado 21 de noviembre a las 3:00 pm' : 'Sat, Nov 21 at 3:00 pm', 'address' => '123 Sample St, Katy, TX', 'package' => $es ? 'Música y Juegos 2.0' : 'Music & Games 2.0',
            'deposit' => '$307.50', 'paid' => '$307.50', 'amount' => '$307.50', 'balance' => '$307.50', 'balance_due' => $es ? ' (vence el viernes 20 de noviembre)' : ' (due Fri, Nov 20)', 'due' => $es ? 'viernes 20 de noviembre' : 'Fri, Nov 20',
            'valid_until' => $es ? 'viernes 30 de octubre' : 'Fri, Oct 30', 'link' => url('/portal/ejemplo'), 'review_link' => 'https://g.page/r/sample'];
        $totals = [['label' => $es ? 'Paquete' : 'Package', 'amount' => '$600'], ['label' => $es ? 'Costo de transporte' : 'Travel fee', 'amount' => '$40'],
            ['label' => $es ? 'Descuento por reservar hoy' : 'Book-today discount', 'amount' => '−$25', 'discount' => true], ['label' => 'Total', 'amount' => '$615', 'strong' => true]];

        return view('mail.layout', ['lang' => $lang, 'mail' => self::render($key, $lang, $vars), 'totals' => $totals, 'logo' => url('/img/waoo-logo.png')])->render();
    }

    /** @return array{subject:string, paragraphs:array, closing:?string, button:?array} */
    public static function render(string $key, string $lang, array $vars): array
    {
        [$subject, $paragraphs, $button, $closing] = self::T[$key][$lang] ?? self::T[$key]['es'];
        $fill = fn (string $s) => preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $s);
        $first = mb_substr((string) ($vars['first_name'] ?? ''), 0, 20);

        return ['subject' => $fill(str_replace('{first_name}', $first, $subject)), 'paragraphs' => array_map(fn ($p) => $p === '__totals__' ? $p : $fill($p), $paragraphs),
            'closing' => $closing, 'button' => $button ? ['label' => $button[0], 'url' => (string) ($vars[$button[1]] ?? '')] : null];
    }
}
