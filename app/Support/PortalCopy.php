<?php

namespace App\Support;

// Client portal copy, ES/EN. Warm, short, Waoo tone; "costo de transporte" / "travel fee".
final class PortalCopy
{
    public const T = [
        'es' => [
            'hello' => '¡Hola, :name!', 'hello_generic' => '¡Hola!', 'intro' => 'Aquí terminas tu reserva en :n pasos. Toma unos 3 minutos.',
            'steps' => ['details' => 'Tus datos', 'contract' => 'Contrato', 'pay' => 'Pago'],
            'your_party' => 'Tu fiesta', 'package' => 'Paquete', 'when' => 'Fecha', 'total' => 'Total', 'travel' => 'Costo de transporte',
            'paid' => 'Ya pagaste', 'balance' => 'Saldo', 'due' => 'vence',
            'details_title' => 'Tus datos', 'required_note' => 'Los campos con * son obligatorios.', 'details_hint' => 'Revisa y completa. Los usamos para el contrato y el día de la fiesta. Solo Waoo Party los ve.',
            'party_time' => 'Horario de la fiesta', 'party_time_hint' => 'Es el horario que acordamos contigo. Si necesitas cambiarlo, escríbenos por WhatsApp.',
            'questions' => 'Sobre la fiesta', 'event_language' => '¿En qué idioma prefieres la fiesta?', 'lang_es' => 'Español', 'lang_en' => 'Inglés', 'lang_both' => 'Bilingüe',
            'setup_area' => '¿Dónde será la actividad?', 'indoor' => 'Interior', 'outdoor' => 'Exterior',
            'children_count' => '¿Cuántos niños aproximadamente?', 'children_hint' => 'Si son más de 25 niños, te recomendamos agregar recreadores: uno por cada 10 niños adicionales, a $70 la hora (mínimo 2 horas).',
            'choose' => 'Elige una opción',
            'first_name' => 'Nombre', 'last_name' => 'Apellido', 'phone' => 'Teléfono', 'email' => 'Correo',
            'billing_address' => 'Tu dirección', 'event_address' => 'Dirección de la fiesta', 'same_address' => 'La fiesta es en mi dirección',
            'start_confirm' => 'La fiesta empieza a las :time', 'start_yes' => 'Sí, esa hora', 'start_no' => 'Necesito otra hora',
            'requested_time' => 'Hora que prefieres', 'requested_hint' => 'Waoo Party la revisa y te confirma; no cambia hasta que te avisen.',
            'child_name' => 'Nombre del festejado/a', 'child_age' => 'Edad que cumple', 'theme' => 'Tema', 'notes' => '¿Alguna petición especial o algo importante que debamos saber?',
            'optional' => 'opcional', 'sms' => 'Acepto recibir mensajes de texto de Waoo Party solo sobre mi reserva: confirmaciones, recordatorios y avisos del día de la fiesta. No enviamos publicidad. Pueden aplicar tarifas de mensajes y datos. Responde STOP para dejar de recibirlos.',
            'save_details' => 'Guardar y seguir',
            'contract_title' => 'Tu contrato', 'contract_hint' => 'Léelo completo. El contrato está en inglés.', 'open_pdf' => 'Abrir el contrato (PDF)',
            'sign_name' => 'Escribe tu nombre completo', 'consent' => 'Acepto firmar electrónicamente y recibir este contrato en formato electrónico.',
            'intent' => 'Leí el contrato y lo firmo con mi nombre escrito arriba.', 'sign' => 'Firmar contrato',
            'pay_title' => 'Tu pago', 'pay_hint' => 'Revisa aquí los pagos registrados y el saldo pendiente.',
            'zelle' => 'Zelle (sin cargo)', 'zelle_to' => 'Envía a', 'zelle_amount' => 'Monto', 'zelle_memo' => 'Escribe en la nota',
            'zelle_note' => 'Cuando lo veamos en el banco te confirmamos. Tu aviso no cuenta como pago hasta entonces.',
            'zelle_sent_btn' => 'Ya envié el Zelle', 'zelle_again' => '¿Enviaste otro Zelle? Avísanos', 'zelle_amount_sent' => 'Monto que enviaste',
            'zelle_payer' => 'Nombre de quien lo envió', 'zelle_ref' => 'Número de confirmación de Zelle', 'zelle_amount_bad' => 'Escribe solo el monto, por ejemplo 250 o 250.50.',
            'zelle_state_pending' => 'Esperando confirmación de Waoo Party: recibimos tu aviso de :amount (:when). Todavía no cuenta como pago.', 'zelle_shot' => 'Captura del Zelle',
            'zelle_state_verified' => 'Verificamos tu Zelle: llegaron :verified. Ya está sumado a tus pagos.',
            'zelle_state_rejected' => 'No pudimos verificar tu aviso de :amount. Escríbenos por WhatsApp y lo revisamos contigo.',
            'card' => 'Tarjeta de crédito o débito', 'card_credit' => 'Tarjeta de crédito', 'card_fee_hint' => 'Pago seguro con Stripe. Las tarjetas tienen un cargo de 3 % por procesamiento; con Zelle no hay cargo.', 'card_hint' => 'Pago seguro con Stripe. Waoo Party nunca ve los datos de tu tarjeta.', 'card_btn' => 'Pagar :amount',
            'done_title' => '¡Todo listo!', 'done' => 'Tu contrato está firmado. Te esperamos el :when. ¡Estamos emocionados de ser parte de tu celebración!',
            'help' => '¿Dudas? Escríbenos por WhatsApp al (346) 851-8830.', 'test' => 'Modo prueba: nada de esto cobra ni envía mensajes.',
            'details_sent_title' => '¡Gracias! Recibimos tus datos', 'details_sent' => 'Waoo Party los revisa y te avisa cuando tu contrato esté listo para firmar.',
            'prepare_contract' => 'Continuar al contrato', 'prepare_saved' => '¡Listo! Tus datos están guardados. Ahora prepara tu contrato para firmarlo.',
            'reload' => 'Volver a revisar',
            'review_details' => 'Revisar mis datos', 'fixed_address_hint' => 'Para cambiar la dirección o el ZIP, escríbenos por WhatsApp antes de firmar.',
            'requested_missing' => 'Escribe la hora que prefieres.',
            'requested_occurrence' => 'Si la hora se repite por el cambio de horario', 'requested_occurrence_default' => 'No aplica / no sé',
            'requested_occurrence_first' => 'Primera vez (CDT)', 'requested_occurrence_second' => 'Segunda vez (CST)',
            'requested_occurrence_hint' => 'Solo aplica a la hora que se repite en noviembre. Si hace falta, te pediremos que elijas.',
            'opening' => 'Abriendo tu reserva…', 'opening_hint' => 'Un momento, por favor.',
            'link_missing' => 'Falta tu enlace', 'link_missing_hint' => 'Abre el enlace completo que te enviamos por WhatsApp o mensaje.',
            'link_expired' => 'Este enlace venció o ya no es válido', 'link_expired_hint' => 'Escríbenos y te mandamos uno nuevo.',
            'waiting_title' => 'Estamos preparando tu contrato', 'waiting' => 'Te avisamos cuando esté listo para firmar.',
            'open_signed' => 'Ver mi contrato firmado', 'contract_preparing' => 'Espera unos segundos para ver tu contrato ya firmado.', 'download_pdf' => 'Descargar (PDF)', 'balance_title' => 'Saldo pendiente', 'balance_due' => 'vence :due', 'balance_not_now' => 'Tu depósito ya está pagado. No tienes que pagar nada más ahora: paga el saldo antes de la fiesta, cuando quieras, desde este mismo link.', 'balance_open' => 'Pagar el saldo', 'pay_retry' => 'No se pudo abrir el pago. Inténtalo de nuevo.',
            'return_processing' => 'Estamos confirmando tu pago con Stripe. Aparecerá aquí cuando se confirme.', 'return_cancelled' => 'No se hizo ningún cobro.',
        ],
        'en' => [
            'hello' => 'Hi, :name!', 'hello_generic' => 'Hi!', 'intro' => 'Finish your booking in :n steps. It takes about 3 minutes.',
            'steps' => ['details' => 'Your details', 'contract' => 'Contract', 'pay' => 'Payment'],
            'your_party' => 'Your party', 'package' => 'Package', 'when' => 'Date', 'total' => 'Total', 'travel' => 'Travel fee',
            'paid' => 'Paid so far', 'balance' => 'Balance', 'due' => 'due',
            'details_title' => 'Your details', 'required_note' => 'Fields marked * are required.', 'details_hint' => 'Please review and complete. We use them for your contract and on the party day. Only Waoo Party sees them.',
            'party_time' => 'Party time', 'party_time_hint' => 'This is the time we agreed with you. If you need to change it, message us on WhatsApp.',
            'questions' => 'About the party', 'event_language' => 'What is your preferred language for the event?', 'lang_es' => 'Spanish', 'lang_en' => 'English', 'lang_both' => 'Bilingual',
            'setup_area' => 'Will the setup area be indoor or outdoor?', 'indoor' => 'Indoor', 'outdoor' => 'Outdoor',
            'children_count' => 'Approximately how many children will attend?', 'children_hint' => 'For more than 25 children we recommend additional party hosts: one for every 10 additional children, at $70 per hour (2-hour minimum).',
            'choose' => 'Choose one',
            'first_name' => 'First name', 'last_name' => 'Last name', 'phone' => 'Phone', 'email' => 'Email',
            'billing_address' => 'Your address', 'event_address' => 'Party address', 'same_address' => 'The party is at my address',
            'start_confirm' => 'The party starts at :time', 'start_yes' => 'Yes, that time', 'start_no' => 'I need a different time',
            'requested_time' => 'Preferred time', 'requested_hint' => 'Waoo Party will review it and confirm; nothing changes until we tell you.',
            'child_name' => 'Name of the celebrant', 'child_age' => 'Age they are turning', 'theme' => 'Theme', 'notes' => 'Any special requests or important information we should know?',
            'optional' => 'optional', 'sms' => 'I agree to receive text messages from Waoo Party only about my booking: confirmations, reminders and party-day updates. No marketing messages. Message and data rates may apply. Reply STOP to opt out.',
            'save_details' => 'Save and continue',
            'contract_title' => 'Your contract', 'contract_hint' => 'Please read it in full.', 'open_pdf' => 'Open the contract (PDF)',
            'sign_name' => 'Type your full name', 'consent' => 'I agree to sign electronically and to receive this contract electronically.',
            'intent' => 'I have read the contract and I sign it with the name typed above.', 'sign' => 'Sign contract',
            'pay_title' => 'Your payment', 'pay_hint' => 'Review your recorded payments and remaining balance here.',
            'zelle' => 'Zelle (no fee)', 'zelle_to' => 'Send to', 'zelle_amount' => 'Amount', 'zelle_memo' => 'Write in the memo',
            'zelle_note' => 'We confirm once we see it in our bank. Your notice is not a payment until then.',
            'zelle_sent_btn' => 'I sent my Zelle payment', 'zelle_again' => 'Sent another Zelle? Let us know', 'zelle_amount_sent' => 'Amount you sent',
            'zelle_payer' => 'Name of the sender', 'zelle_ref' => 'Zelle confirmation number', 'zelle_amount_bad' => 'Enter just the amount, for example 250 or 250.50.',
            'zelle_state_pending' => 'Waiting for Waoo Party to confirm: we got your notice of :amount (:when). It does not count as payment yet.', 'zelle_shot' => 'Zelle screenshot',
            'zelle_state_verified' => 'We verified your Zelle: :verified arrived. It is included in your payments.',
            'zelle_state_rejected' => 'We could not verify your notice for :amount. Message us on WhatsApp and we will check it with you.',
            'card' => 'Credit or debit card', 'card_credit' => 'Credit card', 'card_fee_hint' => 'Secure payment with Stripe. Cards carry a 3% processing fee; Zelle has no fee.', 'card_hint' => 'Secure payment with Stripe. Waoo Party never sees your card details.', 'card_btn' => 'Pay :amount',
            'done_title' => 'All set!', 'done' => 'Your contract is signed. See you on :when. We’re excited to be part of your celebration!',
            'help' => 'Questions? Message us on WhatsApp at (346) 851-8830.', 'test' => 'Test mode: nothing here charges or sends messages.',
            'details_sent_title' => 'Thank you! We received your details', 'details_sent' => 'Waoo Party will review them and let you know when your contract is ready to sign.',
            'prepare_contract' => 'Continue to contract', 'prepare_saved' => 'All set! Your details are saved. Now prepare your contract to sign it.',
            'reload' => 'Review again',
            'review_details' => 'Review my details', 'fixed_address_hint' => 'To change the address or ZIP, message us on WhatsApp before signing.',
            'requested_missing' => 'Please enter your preferred time.',
            'requested_occurrence' => 'If the time repeats when clocks change', 'requested_occurrence_default' => 'Not applicable / unsure',
            'requested_occurrence_first' => 'First occurrence (CDT)', 'requested_occurrence_second' => 'Second occurrence (CST)',
            'requested_occurrence_hint' => 'Only applies to the repeated hour in November. We will ask you to choose if needed.',
            'opening' => 'Opening your booking…', 'opening_hint' => 'One moment, please.',
            'link_missing' => 'Your link is missing', 'link_missing_hint' => 'Open the full link we sent you by WhatsApp or text.',
            'link_expired' => 'This link expired or is no longer valid', 'link_expired_hint' => 'Message us and we will send you a new one.',
            'waiting_title' => 'We are preparing your contract', 'waiting' => 'We will let you know when it is ready to sign.',
            'open_signed' => 'View my signed contract', 'contract_preparing' => 'Wait a few seconds to see your signed contract.', 'download_pdf' => 'Download (PDF)', 'balance_title' => 'Remaining balance', 'balance_due' => 'due :due', 'balance_not_now' => 'Your deposit is paid. You do not need to pay anything else now: pay the balance before the party, whenever you like, from this same link.', 'balance_open' => 'Pay the balance', 'pay_retry' => 'The payment could not open. Please try again.',
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
