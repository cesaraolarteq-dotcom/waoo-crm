<?php

namespace App\Services\Reports\Outbox;

use App\Services\Finance\Sandbox;

final class PreviewGate
{
    public static function require(): void
    {
        Sandbox::require(); Sandbox::require('sandbox_report_outbox');
    }
}
