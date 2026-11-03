<?php

namespace App\Support;

// STAFF-01 staff phone page copy (ES/EN). "My week" + confirm a party + Monday availability: short and friendly; never money, prices or other people's pay.
final class StaffCopy
{
    private const COPY = [
        'es' => [
            'hello' => '¡Hola :name!', 'test' => 'Vista previa con datos de ejemplo.',
            'week' => 'Tus fiestas de esta semana', 'week_range' => 'Semana del :from al :to',
            'saved_link' => 'Este es tu enlace personal: guárdalo en tu celular. Siempre muestra tus fiestas de la semana y se actualiza solo cuando te asignamos una.',
            'none' => 'Esta semana no tienes fiestas asignadas. Si te asignamos una, aparece aquí.',
            'updated' => 'Actualizado el :when',
            'role' => 'Tu papel', 'character' => 'Personaje y traje', 'arrive' => 'Llega a las', 'party' => 'Fiesta', 'setup' => 'Montaje',
            'where' => 'Dirección', 'maps' => 'Abrir en Maps', 'parking' => 'Estacionamiento', 'access' => 'Acceso',
            'team' => 'Vas con', 'drives' => 'maneja', 'you_drive' => 'Tú manejas en esta fiesta.',
            'bring' => 'Lleva', 'notes' => 'Indicaciones de Waoo Party',
            'contact' => 'Contacto del día', 'contact_later' => 'Aparece el día de la fiesta.',
            'help' => '¿Dudas o no puedes ir? Escríbele a César o a Maybeli por WhatsApp lo antes posible.',
            'confirm_q' => '¿Confirmas esta fiesta?', 'confirm_yes' => 'Confirmo', 'confirm_no' => 'No puedo',
            'confirmed' => 'Confirmaste esta fiesta. ¡Gracias!', 'declined' => 'Dijiste que no puedes. César o Maybeli buscarán a alguien más.',
            'change' => 'Cambiar respuesta', 'why_not' => '¿Por qué? (opcional)',
            'avail_h' => '¿Qué días puedes trabajar?', 'avail_sub' => 'Fin de semana del :from. Contesta hoy, así armamos los equipos el martes.',
            'avail_sat' => 'El sábado es el día con más fiestas: es cuando más te necesitamos.',
            'avail_parties' => ':n fiestas', 'avail_party' => '1 fiesta', 'avail_none' => 'sin fiestas aún',
            'yes' => 'Sí puedo', 'no' => 'No puedo', 'avail_note' => 'Algo que debamos saber (opcional)',
            'avail_send' => 'Enviar mis días', 'avail_done' => 'Gracias, ya tenemos tus días. Puedes cambiarlos hasta el martes.',
            'avail_need_sat' => 'Dinos primero si puedes el sábado.', 'saved' => 'Guardado.',
        ],
        'en' => [
            'hello' => 'Hi :name!', 'test' => 'Preview with sample data.',
            'week' => 'Your parties this week', 'week_range' => 'Week of :from to :to',
            'saved_link' => 'This is your personal link: save it on your phone. It always shows your parties for the week and updates on its own when we assign you one.',
            'none' => 'You have no parties assigned this week. If we assign you one, it shows here.',
            'updated' => 'Updated :when',
            'role' => 'Your role', 'character' => 'Character and costume', 'arrive' => 'Arrive at', 'party' => 'Party', 'setup' => 'Setup',
            'where' => 'Address', 'maps' => 'Open in Maps', 'parking' => 'Parking', 'access' => 'Access',
            'team' => 'Going with', 'drives' => 'drives', 'you_drive' => 'You drive for this party.',
            'bring' => 'Bring', 'notes' => 'Notes from Waoo Party',
            'contact' => 'Contact on the day', 'contact_later' => 'Shows on the party day.',
            'help' => "Questions or can't make it? Message César or Maybeli on WhatsApp as soon as possible.",
            'confirm_q' => 'Can you confirm this party?', 'confirm_yes' => 'I confirm', 'confirm_no' => "I can't",
            'confirmed' => 'You confirmed this party. Thank you!', 'declined' => "You said you can't make it. César or Maybeli will find someone else.",
            'change' => 'Change answer', 'why_not' => 'Why? (optional)',
            'avail_h' => 'Which days can you work?', 'avail_sub' => 'Weekend of :from. Please answer today so we can build the teams on Tuesday.',
            'avail_sat' => 'Saturday has the most parties: it is when we need you most.',
            'avail_parties' => ':n parties', 'avail_party' => '1 party', 'avail_none' => 'no parties yet',
            'yes' => 'I can', 'no' => "I can't", 'avail_note' => 'Anything we should know (optional)',
            'avail_send' => 'Send my days', 'avail_done' => 'Thanks, we have your days. You can change them until Tuesday.',
            'avail_need_sat' => 'Tell us first whether you can work Saturday.', 'saved' => 'Saved.',
        ],
    ];

    public static function get(string $lang, string $key, array $replace = []): string
    {
        $s = self::COPY[$lang][$key] ?? self::COPY['es'][$key] ?? $key;
        foreach ($replace as $k => $v) $s = str_replace(':'.$k, (string) $v, $s);

        return $s;
    }
}
