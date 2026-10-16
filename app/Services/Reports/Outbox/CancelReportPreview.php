<?php

namespace App\Services\Reports\Outbox;

use App\Models\{ReportPreviewDelivery, User};
use App\Services\Audit;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;

final class CancelReportPreview
{
    public function execute(User $actor, int $deliveryId, array $input): ReportPreviewDelivery
    {
        PreviewGate::require(); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['note' => 'required|string|min:10|max:500']);
        return DB::transaction(function () use ($actor, $deliveryId, $data) {
            $delivery = ReportPreviewDelivery::whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            abort_unless($delivery->environment === 'test', 404);
            if ($delivery->status === 'cancelled') return $delivery;
            abort_unless(in_array($delivery->status, ['pending', 'processing'], true)
                && !DB::table('report_preview_receipts')->where('delivery_id', $deliveryId)->exists(), 409, 'La tarea ya produjo un recibo o terminó; no se puede anular.');
            $before = $delivery->status;
            $delivery->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id,
                'cancellation_note' => $data['note'], 'lease_token' => null, 'lease_until' => null])->save();
            Audit::recordFor($actor, 'report.preview_cancelled', $delivery, ['status' => $before], ['status' => 'cancelled', 'note' => $data['note']]);
            return $delivery;
        }, 3);
    }
}
