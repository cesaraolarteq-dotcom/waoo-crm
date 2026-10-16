<?php

namespace App\Services\Reports\Outbox;

use App\Models\{OwnerReportSnapshot, ReportPreviewDelivery};
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessReportPreview
{
    public function __construct(private PreviewTransport $transport) {}

    public function execute(int $deliveryId): ReportPreviewDelivery
    {
        PreviewGate::require();
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($deliveryId, $token) {
            $delivery = ReportPreviewDelivery::whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            if (!in_array($delivery->status, ['pending', 'processing'], true) || $delivery->available_at->isFuture()
                || ($delivery->status === 'processing' && $delivery->lease_until?->isFuture())) return false;
            if ($delivery->attempts >= 5) {
                $delivery->forceFill(['status' => 'failed', 'last_error_code' => 'attempt_limit', 'lease_until' => null, 'lease_token' => null])->save();
                Audit::recordSystem('report.preview_exhausted', $delivery);
                return false;
            }
            $delivery->forceFill(['status' => 'processing', 'attempts' => $delivery->attempts + 1,
                'lease_token' => $token, 'lease_until' => now()->addSeconds(90)])->save();
            return true;
        }, 3);
        if (!$claimed) return ReportPreviewDelivery::findOrFail($deliveryId);
        $delivery = ReportPreviewDelivery::findOrFail($deliveryId);
        $snapshot = OwnerReportSnapshot::find($delivery->snapshot_id);
        if (!$snapshot || $snapshot->environment !== 'test' || $snapshot->schema_version !== 1 || $delivery->environment !== 'test'
            || $delivery->recipient !== 'sales@waooparty.com' || $snapshot->recipient !== $delivery->recipient
            || !hash_equals($delivery->payload_sha256, $snapshot->payload_sha256)
            || !hash_equals($snapshot->payload_sha256, hash('sha256', $snapshot->payload_json))) {
            return $this->acknowledge($deliveryId, $token, 'payload_invalid');
        }
        try {
            $this->transport->record($deliveryId, hash('sha256', 'report-preview:test:'.$deliveryId.':v1'), $snapshot->payload_json, $token);
        } catch (\Throwable) {
            // Never persist provider exception strings, payloads or credentials in retry metadata.
            return $this->acknowledge($deliveryId, $token, 'transport_error');
        }
        return $this->acknowledge($deliveryId, $token);
    }

    private function acknowledge(int $id, string $token, ?string $error = null): ReportPreviewDelivery
    {
        return DB::transaction(function () use ($id, $token, $error) {
            $delivery = ReportPreviewDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($delivery->status !== 'processing' || !hash_equals((string) $delivery->lease_token, $token) || !$delivery->lease_until?->isFuture()) return $delivery;
            $terminal = $error === 'payload_invalid' || $delivery->attempts >= 5;
            $delivery->forceFill(['status' => $error === null ? 'completed' : ($terminal ? 'failed' : 'pending'),
                'last_error_code' => $error, 'lease_until' => null, 'lease_token' => null,
                'available_at' => $error && !$terminal ? now()->addSeconds(min(900, 60 * (2 ** ($delivery->attempts - 1)))) : $delivery->available_at,
                'completed_at' => $error === null ? now() : null])->save();
            if ($error === null) Audit::recordSystem('report.preview_completed', $delivery);
            elseif ($terminal) Audit::recordSystem('report.preview_exhausted', $delivery, [], ['error_code' => $error]);
            return $delivery;
        }, 3);
    }
}
