<?php

namespace App\Http\Controllers;

use App\Filament\Support\ReportsSource;
use App\Support\OwnerReportMail;

// LOCAL/TESTING ONLY: design preview of the Monday owner report email with the sample report payloads. Sends nothing.
final class OwnerReportPreviewController extends Controller
{
    public function __invoke()
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        $f = ReportsSource::fixture('week', now('America/Chicago')->subWeek()->toDateString());
        $next = ReportsSource::fixture('week', now('America/Chicago')->toDateString());

        return response()->view('mail.owner-report', ['r' => OwnerReportMail::weekly($f['operations'], $f['bookings'], $next['weekend']),
            'logo' => url('/img/waoo-logo.png'), 'url' => url('/admin/reportes')])->header('Cache-Control', 'no-store, private');
    }
}
