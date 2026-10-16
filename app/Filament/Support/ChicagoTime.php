<?php

namespace App\Filament\Support;

// Compatibility adapter: forms and portal resolve Houston wall times through one shared implementation.
final class ChicagoTime
{
    public const TZ = \App\Support\ChicagoTime::TZ;
    public const CHOICES = \App\Support\ChicagoTime::CHOICES;

    public static function candidates(?string $date, ?string $time): array
    {
        return \App\Support\ChicagoTime::candidates($date, $time);
    }

    public static function isAmbiguous(?string $date, ?string $time): bool
    {
        return \App\Support\ChicagoTime::isAmbiguous($date, $time);
    }

    public static function toIso(string $date, string $time, ?string $choice, string $field): string
    {
        return \App\Support\ChicagoTime::toIso($date, $time, $choice, $field);
    }

    public static function toLocalParts(?\DateTimeInterface $at): array
    {
        return \App\Support\ChicagoTime::toLocalParts($at);
    }
}
