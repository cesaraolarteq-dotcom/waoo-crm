<?php

namespace App\Support;

// STAFF-01 staff phone portal copy (ES/EN). Short and friendly; never mentions money, prices or other people's pay.
final class StaffCopy
{
    private const COPY = [
        'es' => [
            'hello' => '¡Hola :name!', 'test' => 'Vista previa con datos de ejemplo. Nada se guarda.',
            'tab_home' => 'Mis trabajos', 'tab_availability' => 'Mi disponibilidad',
            'invites' => 'Te invitamos', 'invites_none' => 'No tienes invitaciones nuevas.', 'answer_by' => 'Respóndenos antes del :date',
            'accept' => 'Sí, voy', 'decline' => 'No puedo', 'decline_why' => 'Cuéntanos por qué (opcional)', 'decline_send' => 'Enviar respuesta',
            'accept_note' => 'Al aceptar te comprometes a ir. Si después no puedes, avísanos lo antes posible.',
            'upcoming' => 'Tus próximos trabajos', 'upcoming_none' => 'Todavía no tienes trabajos aceptados.', 'open' => 'Ver detalles',
            'role' => 'Tu papel', 'arrive' => 'Llega a las', 'party' => 'Fiesta', 'area' => 'Zona', 'address_after' => 'La dirección exacta aparece cuando aceptas.',
            'job' => 'Detalles del trabajo', 'back' => '← Mis trabajos', 'when' => 'Fecha', 'times' => 'Horario',
            'times_line' => 'Llegada :arrive · montaje :setup · fiesta :start–:end', 'where' => 'Dirección', 'parking' => 'Estacionamiento y acceso',
            'character' => 'Personaje y traje', 'team' => 'Tu equipo', 'driver' => 'maneja', 'bring' => 'Lleva', 'notes' => 'Indicaciones de Waoo Party',
            'contact' => 'Contacto del día', 'contact_later' => 'Aparece el día de la fiesta.', 'maps' => 'Abrir en Maps',
            'availability' => '¿Cuándo puedes trabajar?', 'availability_hint' => 'Marca los horarios en que normalmente puedes. Esto no es un compromiso: cada trabajo te lo confirmamos aparte.',
            'morning' => 'Mañana', 'afternoon' => 'Tarde', 'evening' => 'Noche', 'exceptions' => 'Días que no puedes', 'add_exception' => 'Agregar un día',
            'save' => 'Guardar', 'help' => '¿Dudas? Escríbele a César o a Maybeli por WhatsApp.',
            'days' => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
        ],
        'en' => [
            'hello' => 'Hi :name!', 'test' => 'Preview with sample data. Nothing is saved.',
            'tab_home' => 'My jobs', 'tab_availability' => 'My availability',
            'invites' => 'You are invited', 'invites_none' => 'No new invitations.', 'answer_by' => 'Please answer by :date',
            'accept' => "Yes, I'll go", 'decline' => "I can't", 'decline_why' => 'Tell us why (optional)', 'decline_send' => 'Send answer',
            'accept_note' => "By accepting you commit to go. If you can't later, let us know as soon as possible.",
            'upcoming' => 'Your next jobs', 'upcoming_none' => 'No accepted jobs yet.', 'open' => 'See details',
            'role' => 'Your role', 'arrive' => 'Arrive at', 'party' => 'Party', 'area' => 'Area', 'address_after' => 'The exact address shows after you accept.',
            'job' => 'Job details', 'back' => '← My jobs', 'when' => 'Date', 'times' => 'Times',
            'times_line' => 'Arrive :arrive · setup :setup · party :start–:end', 'where' => 'Address', 'parking' => 'Parking and access',
            'character' => 'Character and costume', 'team' => 'Your team', 'driver' => 'drives', 'bring' => 'Bring', 'notes' => 'Notes from Waoo Party',
            'contact' => 'Contact on the day', 'contact_later' => 'Shows on the party day.', 'maps' => 'Open in Maps',
            'availability' => 'When can you work?', 'availability_hint' => 'Mark the times you can usually work. This is not a commitment: we confirm each job separately.',
            'morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening', 'exceptions' => "Days you can't", 'add_exception' => 'Add a day',
            'save' => 'Save', 'help' => 'Questions? Message César or Maybeli on WhatsApp.',
            'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        ],
    ];

    public static function get(string $lang, string $key, array $replace = []): string|array
    {
        $s = self::COPY[$lang][$key] ?? self::COPY['es'][$key] ?? $key;
        if (is_string($s)) foreach ($replace as $k => $v) $s = str_replace(':'.$k, (string) $v, $s);

        return $s;
    }
}
