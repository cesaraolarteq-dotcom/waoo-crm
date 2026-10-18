<?php

namespace App\Support;

// STAFF-01 staff phone page copy (ES/EN). Read-only "my week": short and friendly; never money, prices or other people's pay.
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
        ],
    ];

    public static function get(string $lang, string $key, array $replace = []): string
    {
        $s = self::COPY[$lang][$key] ?? self::COPY['es'][$key] ?? $key;
        foreach ($replace as $k => $v) $s = str_replace(':'.$k, (string) $v, $s);

        return $s;
    }
}
