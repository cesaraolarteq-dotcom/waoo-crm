<?php
// Automatic messages page (owner 2026-11-23). Texts proposed by Claude that César approves on the page itself
// (Vender → Mensajes automáticos → "Aprobar texto"). The approval stores a hash of the exact text: changing a word here
// turns it back into "Propuesto" until he approves it again. The seven emails in client-texts.php are already approved.
return [
    'contract_signed_email' => [
        'es' => [
            'subject' => '¡Recibimos tu contrato firmado!',
            'paragraphs' => ['¡Gracias, {first_name}! Tu contrato de Waoo Party para el {event_date} quedó firmado.',
                'Pagado hasta hoy: {paid} · Saldo: {balance}{balance_due}.'],
            'button' => 'Ver mi reserva y mi contrato',
            'closing' => '¡Estamos emocionados de ser parte de tu celebración!',
        ],
        'en' => [
            'subject' => 'We received your signed contract!',
            'paragraphs' => ['Thank you, {first_name}! Your Waoo Party contract for {event_date} is signed.',
                'Paid so far: {paid} · Balance: {balance}{balance_due}.'],
            'button' => 'See my booking and contract',
            'closing' => "We're excited to be part of your celebration!",
        ],
    ],
    // Text messages: short, brand first (A2P), only to clients who accepted texts. {link} is the client's private page.
    'sms' => [
        'payment_received' => [
            'es' => 'Waoo Party: ¡Gracias, {first_name}! Recibimos tu pago de {amount}. Saldo: {balance}.',
            'en' => 'Waoo Party: Thank you, {first_name}! We received your payment of {amount}. Balance: {balance}.',
        ],
        'contract_signed' => [
            'es' => 'Waoo Party: ¡Gracias, {first_name}! Recibimos tu contrato firmado para el {event_date}. Tu reserva: {link}',
            'en' => 'Waoo Party: Thank you, {first_name}! We received your signed contract for {event_date}. Your booking: {link}',
        ],
        'balance_reminder' => [
            'es' => 'Waoo Party: Hola {first_name}, tu saldo de {balance} vence el {due}. Zelle 346-216-8739 (sin cargo) o tarjeta aquí: {link}',
            'en' => 'Waoo Party: Hi {first_name}, your balance of {balance} is due {due}. Zelle 346-216-8739 (no fee) or card here: {link}',
        ],
        'week_before' => [
            'es' => 'Waoo Party: ¡Falta una semana, {first_name}! Tu fiesta es el {event_date}. Revisa los detalles aquí: {link}',
            'en' => 'Waoo Party: One week to go, {first_name}! Your party is on {event_date}. Check the details here: {link}',
        ],
        'thank_you_review' => [
            'es' => 'Waoo Party: ¡Gracias por celebrar con nosotros, {first_name}! Si te gustó, tu reseña nos ayuda muchísimo: {review_link}',
            'en' => 'Waoo Party: Thank you for celebrating with us, {first_name}! If you enjoyed it, a review helps us a lot: {review_link}',
        ],
    ],
];
