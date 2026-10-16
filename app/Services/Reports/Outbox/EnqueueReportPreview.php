<?php

namespace App\Services\Reports\Outbox;

use App\Models\{OwnerReportSnapshot, ReportPreviewDelivery, User};
use App\Services\Audit;
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\DB;

final class EnqueueReportPreview
{
    public function execute(User $actor, int $snapshotId): ReportPreviewDelivery
    {
        PreviewGate::require(); $actor = RecordActor::authorize($actor);
        return DB::transaction(function () use ($actor, $snapshotId) {
            $snapshot = OwnerReportSnapshot::whereKey($snapshotId)->lockForUpdate()->firstOrFail();
            abort_unless($snapshot->environment === 'test' && $snapshot->schema_version === 1 && $snapshot->recipient === 'sales@waooparty.com'
                && hash_equals($snapshot->payload_sha256, hash('sha256', $snapshot->payload_json)), 409, 'Revisa la integridad del reporte preparado.');
            if ($saved = ReportPreviewDelivery::where('snapshot_id', $snapshotId)->first()) return $saved;
            $delivery = ReportPreviewDelivery::create(['snapshot_id' => $snapshotId, 'environment' => 'test', 'recipient' => $snapshot->recipient,
                'payload_sha256' => $snapshot->payload_sha256, 'status' => 'pending', 'attempts' => 0, 'available_at' => now()]);
            Audit::recordFor($actor, 'report.preview_queued', $delivery, [], ['snapshot_id' => $snapshotId]);
            return $delivery;
        }, 3);
    }
}
