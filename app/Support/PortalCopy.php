<?php

namespace App\Support;

// Client portal copy, ES/EN. Warm, short, Waoo tone; "costo de transporte" / "travel fee".
final class PortalCopy
{
    public const T = [
        'es' => [
            'hello' => '¡Hola, :name!', 'intro' => 'Aquí terminas tu reserva en :n pasos. Toma unos 3 minutos.',
            'steps' => ['details' => 'Tus datos', 'contract' => 'Contrato', 'pay' => 'Pago'],
            'your_party' => 'Tu fiesta', 'package' => 'Paquete', 'when' => 'Fecha', 'total' => 'Total', 'travel' => 'Costo de transporte',
            'paid' => 'Ya pagaste', 'balance' => 'Saldo', 'due' => 'vence',
            'details_title' => 'Tus datos', 'details_hint' => 'Los usamos para el contrato y el día de la fiesta. Solo Waoo Party los ve.',
            'first_name' => 'Nombre', 'last_name' => 'Apellido', 'phone' => 'Teléfono', 'email' => 'Correo',
            'billing_address' => 'Tu dirección', 'event_address' => 'Dirección de la fiesta', 'same_address' => 'La fiesta es en mi dirección',
            'start_confirm' => 'La fiesta empieza a las :time', 'start_yes' => 'Sí, esa hora', 'start_no' => 'Necesito otra hora',
            'requested_time' => 'Hora que prefieres', 'requested_hint' => 'Waoo Party la revisa y te confirma; no cambia hasta que te avisen.',
            'child_name' => 'Nombre del cumpleañero/a', 'child_age' => 'Edad que cumple', 'theme' => 'Tema', 'notes' => 'Algo que debamos saber',
            'optional' => 'opcional', 'sms' => 'Acepto recibir mensajes de texto de Waoo Party sobre mi reserva. Responde STOP para dejar de recibirlos.',
            'save_details' => 'Guardar y seguir',
            'contract_title' => 'Tu contrato', 'contract_hint' => 'Léelo completo. El contrato está en inglés.', 'open_pdf' => 'Abrir el contrato (PDF)',
            'sign_name' => 'Escribe tu nombre completo', 'consent' => 'Acepto firmar electrónicamente y recibir este contrato en formato electrónico.',
            'intent' => 'Leí el contrato y lo firmo con mi nombre escrito arriba.', 'sign' => 'Firmar contrato',
            'pay_title' => 'Tu pago', 'pay_hint' => 'Tu fecha ya está apartada con el depósito. Puedes pagar el saldo cuando quieras antes de la fecha límite.',
            'zelle' => 'Zelle (sin cargo)', 'zelle_to' => 'Envía a', 'zelle_amount' => 'Monto', 'zelle_memo' => 'Escribe en la nota',
            'zelle_note' => 'Cuando lo veamos en el banco te confirmamos. Tu aviso no cuenta como pago hasta entonces.',
            'card' => 'Tarjeta de crédito o débito', 'card_hint' => 'Pago seguro con Stripe. Waoo Party nunca ve los datos de tu tarjeta.', 'card_btn' => 'Pagar :amount',
            'done_title' => '¡Todo listo!', 'done' => 'Tu contrato está firmado. Te esperamos el :when. ¡Estamos emocionados de ser parte de tu celebración!',
            'help' => '¿Dudas? Escríbenos por WhatsApp al (346) 851-8830.', 'test' => 'Modo prueba: nada de esto cobra ni envía mensajes.',
            'details_sent_title' => '¡Gracias! Recibimos tus datos', 'details_sent' => 'Waoo Party los revisa y te avisa cuando tu contrato esté listo para firmar.',
            'requested_missing' => 'Escribe la hora que prefieres.',
            'opening' => 'Abriendo tu reserva…', 'opening_hint' => 'Un momento, por favor.',
            'link_missing' => 'Falta tu enlace', 'link_missing_hint' => 'Abre el enlace completo que te enviamos por WhatsApp o mensaje.',
            'link_expired' => 'Este enlace venció o ya no es válido', 'link_expired_hint' => 'Escríbenos y te mandamos uno nuevo.',
            'waiting_title' => 'Estamos preparando tu contrato', 'waiting' => 'Te avisamos cuando esté listo para firmar.',
            'open_signed' => 'Ver mi contrato firmado', 'pay_retry' => 'No se pudo abrir el pago. Inténtalo de nuevo.',
            'return_processing' => 'Estamos confirmando tu pago con Stripe. Aparecerá aquí cuando se confirme.', 'return_cancelled' => 'No se hizo ningún cobro.',
        ],
        'en' => [
            'hello' => 'Hi, :name!', 'intro' => 'Finish your booking in :n steps. It takes about 3 minutes.',
            'steps' => ['details' => 'Your details', 'contract' => 'Contract', 'pay' => 'Payment'],
            'your_party' => 'Your party', 'package' => 'Package', 'when' => 'Date', 'total' => 'Total', 'travel' => 'Travel fee',
            'paid' => 'Paid so far', 'balance' => 'Balance', 'due' => 'due',
            'details_title' => 'Your details', 'details_hint' => 'We use them for your contract and on the party day. Only Waoo Party sees them.',
            'first_name' => 'First name', 'last_name' => 'Last name', 'phone' => 'Phone', 'email' => 'Email',
            'billing_address' => 'Your address', 'event_address' => 'Party address', 'same_address' => 'The party is at my address',
            'start_confirm' => 'The party starts at :time', 'start_yes' => 'Yes, that time', 'start_no' => 'I need a different time',
            'requested_time' => 'Preferred time', 'requested_hint' => 'Waoo Party will review it and confirm; nothing changes until we tell you.',
            'child_name' => 'Birthday child’s name', 'child_age' => 'Turning', 'theme' => 'Theme', 'notes' => 'Anything we should know',
            'optional' => 'optional', 'sms' => 'I agree to receive text messages from Waoo Party about my booking. Reply STOP to opt out.',
            'save_details' => 'Save and continue',
            'contract_title' => 'Your contract', 'contract_hint' => 'Please read it in full.', 'open_pdf' => 'Open the contract (PDF)',
            'sign_name' => 'Type your full name', 'consent' => 'I agree to sign electronically and to receive this contract electronically.',
            'intent' => 'I have read the contract and I sign it with the name typed above.', 'sign' => 'Sign contract',
            'pay_title' => 'Your payment', 'pay_hint' => 'Your date is held with your deposit. You can pay the balance anytime before the due date.',
            'zelle' => 'Zelle (no fee)', 'zelle_to' => 'Send to', 'zelle_amount' => 'Amount', 'zelle_memo' => 'Write in the memo',
            'zelle_note' => 'We confirm once we see it in our bank. Your notice is not a payment until then.',
            'card' => 'Credit or debit card', 'card_hint' => 'Secure payment with Stripe. Waoo Party never sees your card details.', 'card_btn' => 'Pay :amount',
            'done_title' => 'All set!', 'done' => 'Your contract is signed. See you on :when. We’re excited to be part of your celebration!',
            'help' => 'Questions? Message us on WhatsApp at (346) 851-8830.', 'test' => 'Test mode: nothing here charges or sends messages.',
            'details_sent_title' => 'Thank you! We received your details', 'details_sent' => 'Waoo Party will review them and let you know when your contract is ready to sign.',
            'requested_missing' => 'Please enter your preferred time.',
            'opening' => 'Opening your booking…', 'opening_hint' => 'One moment, please.',
            'link_missing' => 'Your link is missing', 'link_missing_hint' => 'Open the full link we sent you by WhatsApp or text.',
            'link_expired' => 'This link expired or is no longer valid', 'link_expired_hint' => 'Message us and we will send you a new one.',
            'waiting_title' => 'We are preparing your contract', 'waiting' => 'We will let you know when it is ready to sign.',
            'open_signed' => 'View my signed contract', 'pay_retry' => 'The payment could not open. Please try again.',
            'return_processing' => 'We are confirming your payment with Stripe. It will show here once confirmed.', 'return_cancelled' => 'No charge was made.',
        ],
    ];

    public static function get(string $lang, string $key, array $replace = []): string|array
    {
        $value = self::T[$lang][$key] ?? self::T['es'][$key];
        if (is_string($value)) {
            foreach ($replace as $k => $v) {
                $value = str_replace(':'.$k, (string) $v, $value);
            }
            $value = str_replace(['..', '.)'], ['.', ')'], $value); // dates like "3:00 p. m." before a period
        }

        return $value;
    }
}
