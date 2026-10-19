<?php

namespace App\Support;

// Client quote page copy (QUOTE-01 B screens). Warm, short, the owner's voice; ES says "costo de transporte".
// Consent wording for accepting AND rejecting is NOT here: it is the frozen approved text of the presentation (QUOTE-PORTAL-API B).
final class QuoteCopy
{
    private const COPY = [
        'es' => [
            'title' => 'Tu cotización', 'hello' => '¡Hola :name!', 'intro' => 'Aquí está tu cotización de Waoo Party. Revísala con calma y, si te gusta, avísanos aquí abajo.',
            'number' => 'Cotización :number · versión :rev', 'valid' => 'Válida hasta el :date', 'party' => 'Tu fiesta', 'when' => 'Fecha', 'where' => 'Lugar',
            'services' => 'Lo que incluye', 'travel' => 'Costo de transporte', 'subtotal' => 'Subtotal', 'discount' => 'Descuento', 'total' => 'Total',
            'deposit' => 'Para apartar tu fecha', 'deposit_hint' => 'Depósito del 50 %: :amount. El saldo se paga antes de la fiesta.',
            'pdf' => 'Ver PDF', 'accept_title' => '¿Te gusta?', 'name' => 'Tu nombre completo', 'email' => 'Tu correo', 'accept' => 'Me interesa, quiero reservar',
            'changes' => '¿Quieres cambiar algo? Escríbenos por WhatsApp al (346) 851-8830 y te mandamos una versión nueva.',
            'reject' => 'No me interesa por ahora', 'reject_send' => 'Enviar respuesta',
            'pdf_first' => 'Primero abre el PDF completo de tu cotización; después puedes responder.', 'identity_required' => 'Escribe tu nombre completo y tu correo.',
            'not_available' => 'Por ahora no puedes responder desde aquí. Escríbenos y te ayudamos.',
            'accepted_title' => '¡Gracias! Recibimos tu respuesta', 'accepted' => 'Te escribimos muy pronto con el contrato y el depósito para apartar tu fecha. ¡Estamos emocionados de ser parte de tu celebración!',
            'expired_title' => 'Esta cotización ya venció', 'expired' => 'Escríbenos y con gusto revisamos disponibilidad y te mandamos una nueva.',
            'superseded_title' => 'Hay una versión más nueva', 'superseded' => 'Esta versión ya no está vigente. Pídenos el enlace actualizado si no lo tienes.',
            'rejected_title' => 'Recibimos tu respuesta', 'rejected' => 'Gracias por avisarnos. Si cambias de idea, aquí estamos.',
            'error' => 'No se pudo guardar. Inténtalo de nuevo.', 'test' => 'Modo prueba: nada de esto cobra ni envía mensajes.',
        ],
        'en' => [
            'title' => 'Your quote', 'hello' => 'Hi :name!', 'intro' => 'Here is your Waoo Party quote. Take your time to review it and, if you like it, let us know below.',
            'number' => 'Quote :number · version :rev', 'valid' => 'Valid until :date', 'party' => 'Your party', 'when' => 'Date', 'where' => 'Place',
            'services' => 'What is included', 'travel' => 'Travel fee', 'subtotal' => 'Subtotal', 'discount' => 'Discount', 'total' => 'Total',
            'deposit' => 'To reserve your date', 'deposit_hint' => '50% deposit: :amount. The balance is paid before the party.',
            'pdf' => 'View PDF', 'accept_title' => 'Do you like it?', 'name' => 'Your full name', 'email' => 'Your email', 'accept' => "I'm interested, let's book",
            'changes' => 'Want to change something? Message us on WhatsApp at (346) 851-8830 and we will send you a new version.',
            'reject' => 'Not interested for now', 'reject_send' => 'Send answer',
            'pdf_first' => 'First open the full PDF of your quote; then you can answer.', 'identity_required' => 'Please enter your full name and email.',
            'not_available' => 'You cannot answer from here right now. Message us and we will help you.',
            'accepted_title' => 'Thank you! We got your answer', 'accepted' => "We'll message you very soon with the contract and deposit to reserve your date. We're excited to be part of your celebration!",
            'expired_title' => 'This quote has expired', 'expired' => "Message us and we'll gladly check availability and send you a new one.",
            'superseded_title' => 'There is a newer version', 'superseded' => 'This version is no longer current. Ask us for the updated link if you do not have it.',
            'rejected_title' => 'We got your answer', 'rejected' => "Thanks for letting us know. If you change your mind, we're here.",
            'error' => 'It could not be saved. Please try again.', 'test' => 'Test mode: nothing here charges or sends messages.',
        ],
    ];

    public static function get(string $lang, string $key, array $replace = []): string
    {
        $s = self::COPY[$lang][$key] ?? self::COPY['es'][$key] ?? $key;
        foreach ($replace as $k => $v) $s = str_replace(':'.$k, (string) $v, $s);

        return $s;
    }
}
