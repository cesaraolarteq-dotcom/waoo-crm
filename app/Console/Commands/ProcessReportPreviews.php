<?php

namespace App\Console\Commands;

use App\Models\ReportPreviewDelivery;
use App\Services\Reports\Outbox\{PreviewGate, ProcessReportPreview};
use Illuminate\Console\Command;

class ProcessReportPreviews extends Command
{
    protected $signature = 'reports:process-previews {--limit=10}';
    protected $description = 'Record due local report previews; never send email';

    public function handle(ProcessReportPreview $processor): int
    {
        PreviewGate::require();
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) { $this->error('limit debe estar entre 1 y 100.'); return self::INVALID; }
        $ids = ReportPreviewDelivery::where('environment', 'test')->where('available_at', '<=', now())
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'processing')->where('lease_until', '<=', now())))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) $processor->execute($id);
        $this->info('Vistas locales revisadas: '.$ids->count().'. No se enviaron correos.');
        return self::SUCCESS;
    }
}
