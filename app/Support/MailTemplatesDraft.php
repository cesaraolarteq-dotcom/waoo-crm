<?php

namespace App\Support;

// MAIL-01 v1 DRAFT copy (docs/templates/email/MAIL-01-templates-v1.md), for the design preview only.
// Not approved by the owner, not used for sending. Each template: subject, paragraphs, optional button (label → link variable), closing line.
// Subjects take only short values: {subject_date} is the date without the time (longest ES "miércoles 30 de septiembre"), {due} is date-only, first_name is cut to 20.
final class MailTemplatesDraft
{
    public const KEYS = ['inquiry_received', 'quote_sent', 'details_request', 'contract_ready', 'payment_received', 'zelle_notice_pending', 'balance_reminder', 'week_before', 'thank_you_review'];

    private const T = [
        'inquiry_received' => ['es' => ['¡Recibimos tu solicitud, {first_name}!', ['¡Hola {first_name}! Gracias por escribir a Waoo Party. Recibimos tu solicitud para el {event_date} y ya estamos revisando la disponibilidad para tu código postal.', 'Muy pronto te enviamos opciones y el precio completo, con el costo de transporte incluido. Si prefieres hablar antes, escríbenos o llámanos al (346) 851-8830.'], null, '¡Estamos emocionados de ser parte de tu celebración!'],
                               'en' => ['We got your request, {first_name}!', ["Hi {first_name}! Thank you for contacting Waoo Party. We received your request for {event_date} and we're checking availability for your ZIP code now.", "We'll reply very soon with options and the complete price, including the travel fee. If you'd like to talk sooner, text or call us at (346) 851-8830."], null, "We're excited to be part of your celebration!"]],
        'quote_sent' => ['es' => ['Tu cotización Waoo Party para el {subject_date}', ['¡Hola {first_name}! Aquí está tu cotización de {package} para el {event_date}.', '__totals__', 'Para apartar tu fecha pedimos un depósito del 50 % ({deposit}). Esta cotización es válida hasta el {valid_until}.'], ['Ver y aceptar mi cotización', 'link'], '¡Estamos emocionados de ser parte de tu celebración!'],
                         'en' => ['Your Waoo Party quote for {subject_date}', ['Hi {first_name}! Here is your quote for {package} on {event_date}.', '__totals__', 'To reserve your date we need a 50% deposit ({deposit}). This quote is valid until {valid_until}.'], ['Review and accept my quote', 'link'], "We're excited to be part of your celebration!"]],
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
