<?php

namespace App\Services\Reports\Outbox;

use App\Models\ReportPreviewDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabasePreviewTransport implements PreviewTransport
{
    public function record(int $deliveryId, string $key, string $payloadJson, string $leaseToken): string
    {
        PreviewGate::require();
        return DB::transaction(function () use ($deliveryId, $key, $payloadJson, $leaseToken) {
            // Separate transaction from claim/ack: this durable receipt survives a lost ack.
            $delivery = ReportPreviewDelivery::whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            if ($delivery->status !== 'processing' || !$delivery->lease_until?->isFuture()
                || !hash_equals((string) $delivery->lease_token, $leaseToken)) {
                throw new \LogicException('Preview claim is no longer active.');
            }
            $hash = hash('sha256', $payloadJson);
            if ($delivery->environment !== 'test' || $delivery->recipient !== 'sales@waooparty.com'
                || !hash_equals($delivery->payload_sha256, $hash)
                || !hash_equals(hash('sha256', 'report-preview:test:'.$deliveryId.':v1'), $key)) {
                throw new \LogicException('Invalid local preview identity.');
            }
            $existing = DB::table('report_preview_receipts')->where('delivery_id', $deliveryId)->first();
            if ($existing) {
                if (!hash_equals($existing->idempotency_key, $key) || !hash_equals($existing->payload_sha256, $hash)) throw new \LogicException('Preview receipt identity changed.');
                return $existing->reference;
            }
            $reference = (string) Str::uuid();
            DB::table('report_preview_receipts')->insert(['delivery_id' => $deliveryId, 'idempotency_key' => $key,
                'payload_sha256' => $hash, 'reference' => $reference, 'created_at' => now(), 'updated_at' => now()]);
            return $reference;
        }, 3);
    }
}
