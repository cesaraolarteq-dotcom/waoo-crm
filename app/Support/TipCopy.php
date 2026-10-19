<?php

namespace App\Support;

// TIP-01 client tip page copy (ES/EN). Owner 2026-10-18: card only (Stripe), 5/10/15 % of the party total or another amount,
// 100 % to the party's staff; card fees are Waoo's and are never mentioned to the client.
final class TipCopy
{
    private const COPY = [
        'es' => [
            'title' => '¿Quieres agradecerle al equipo?', 'hello' => '¡Gracias por celebrar con nosotros, :name!',
            'intro' => 'Si quieres, puedes dejarle una propina al equipo de tu fiesta. El 100 % es para el staff que te acompañó.',
            'party' => 'Tu fiesta del :when', 'team' => 'Te acompañaron: :names',
            'choose' => 'Elige una propina', 'pct_hint' => 'Porcentajes del total de tu fiesta (:total).',
            'other' => 'Otro monto', 'other_hint' => 'Escribe el monto en dólares.', 'pay' => 'Dejar propina de :amount', 'pay_none' => 'Elige un monto',
            'card' => 'Pago seguro con tarjeta, Apple Pay o Google Pay.', 'optional' => 'La propina es opcional. ¡Gracias de todas formas!',
            'thanks_title' => '¡Gracias por tu propina!', 'thanks' => 'Le llega completa al equipo de tu fiesta. ¡Nos encantaría celebrar contigo otra vez!',
            'review' => 'Dejar una reseña en Google', 'error_amount' => 'Escribe un monto entre $1 y $2,000 (por ejemplo 25 o 25.50).',
            'error' => 'No se pudo abrir el pago. Inténtalo de nuevo en un momento.', 'stale' => 'Esta página cambió. Recárgala para ver los montos actualizados.',
            'closed_title' => 'Este enlace ya no está activo', 'closed' => 'Si quieres dejar una propina, escríbenos y te mandamos uno nuevo.',
        ],
        'en' => [
            'title' => 'Would you like to thank the team?', 'hello' => 'Thank you for celebrating with us, :name!',
            'intro' => 'If you like, you can leave a tip for the team at your party. 100% goes to the staff who were with you.',
            'party' => 'Your party on :when', 'team' => 'Your team: :names',
            'choose' => 'Choose a tip', 'pct_hint' => 'Percentages of your party total (:total).',
            'other' => 'Other amount', 'other_hint' => 'Enter the amount in dollars.', 'pay' => 'Leave a :amount tip', 'pay_none' => 'Choose an amount',
            'card' => 'Secure payment by card, Apple Pay or Google Pay.', 'optional' => 'Tips are optional. Thank you either way!',
            'thanks_title' => 'Thank you for your tip!', 'thanks' => "It goes in full to your party's team. We'd love to celebrate with you again!",
            'review' => 'Leave a Google review', 'error_amount' => 'Enter an amount between $1 and $2,000 (for example 25 or 25.50).',
            'error' => 'The payment could not be opened. Please try again in a moment.', 'stale' => 'This page changed. Reload it to see the updated amounts.',
            'closed_title' => 'This link is no longer active', 'closed' => 'If you would like to leave a tip, message us and we will send you a new one.',
        ],
    ];

    public static function get(string $lang, string $key, array $replace = []): string
    {
        $s = self::COPY[$lang][$key] ?? self::COPY['es'][$key] ?? $key;
        foreach ($replace as $k => $v) $s = str_replace(':'.$k, (string) $v, $s);

        return $s;
    }
}
