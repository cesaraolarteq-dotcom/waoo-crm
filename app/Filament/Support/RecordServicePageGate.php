<?php

namespace App\Filament\Support;

final class RecordServicePageGate
{
    // Forms switch on only when the audited service class exists. Checked per request. Config flag can force them off.
    public static function ready(string $service): bool
    {
        return config('waoo.record_forms', true) && (class_exists($service) || app()->bound($service));
    }
}
