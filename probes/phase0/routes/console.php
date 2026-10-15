<?php

use App\Jobs\ProbeFlakyJob;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// The hPanel cron runs `schedule:run` every minute; it drains the queue without needing a permanent worker.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping();

Artisan::command('probe:job', function () {
    $id = now()->format('His').'-'.Str::random(4);
    ProbeFlakyJob::dispatch($id);
    $this->info("P1 dispatched run {$id} at ".now()->toDateTimeString());
});

Artisan::command('probe:pdf', function () {
    $pdf = new Dompdf();
    $pdf->loadHtml('<h1>Waoo CRM probe</h1><p>Fictitious document generated '.now()->toDateTimeString().' (America/Chicago test).</p>');
    $pdf->render();
    Storage::disk('local')->put('private/probe.pdf', $pdf->output());
    $this->info('P2 written: '.Storage::disk('local')->path('private/probe.pdf'));
    $this->info('Signed URL (10 min): '.URL::temporarySignedRoute('probe.pdf', now()->addMinutes(10)));
});

Artisan::command('probe:lock {label}', function (string $label) {
    DB::table('probe_counters')->insertOrIgnore(['name' => 'lock', 'value' => 0]);
    DB::transaction(function () use ($label) {
        $row = DB::table('probe_counters')->where('name', 'lock')->lockForUpdate()->first();
        $this->info("P4 {$label} got lock at ".microtime(true)." value={$row->value}");
        sleep(3);
        DB::table('probe_counters')->where('name', 'lock')->update(['value' => $row->value + 1]);
    });
    $this->info("P4 {$label} committed at ".microtime(true));
});
