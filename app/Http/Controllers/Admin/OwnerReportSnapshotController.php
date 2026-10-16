<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OwnerReportSnapshot;
use App\Services\Finance\Sandbox;
use App\Services\Records\RecordActor;
use App\Services\Reports\PrepareOwnerReport;
use Illuminate\Http\Request;

final class OwnerReportSnapshotController extends Controller
{
    public function store(Request $request, PrepareOwnerReport $service)
    {
        return response()->json($service->execute($request->user(), $request->all()), 201)->header('Cache-Control', 'no-store, private');
    }
    public function preview(Request $request, OwnerReportSnapshot $snapshot, \App\Services\Reports\Outbox\EnqueueReportPreview $service)
    {
        return response()->json($service->execute($request->user(), $snapshot->id), 201)->header('Cache-Control', 'no-store, private');
    }
    public function previewStatus(Request $request, OwnerReportSnapshot $snapshot)
    {
        \App\Services\Reports\Outbox\PreviewGate::require();
        RecordActor::authorize($request->user());
        $delivery = \App\Models\ReportPreviewDelivery::where('snapshot_id', $snapshot->id)->where('environment', 'test')->firstOrFail();
        return response()->json($delivery)->header('Cache-Control', 'no-store, private');
    }
    public function cancelPreview(Request $request, OwnerReportSnapshot $snapshot, \App\Services\Reports\Outbox\CancelReportPreview $service)
    {
        \App\Services\Reports\Outbox\PreviewGate::require();
        RecordActor::authorize($request->user());
        $delivery = \App\Models\ReportPreviewDelivery::where('snapshot_id', $snapshot->id)->firstOrFail();
        return response()->json($service->execute($request->user(), $delivery->id, $request->all()))->header('Cache-Control', 'no-store, private');
    }
    public function show(Request $request, OwnerReportSnapshot $snapshot)
    {
        Sandbox::require();
        RecordActor::authorize($request->user());
        abort_unless($snapshot->environment === 'test', 404);
        return response()->json($snapshot)->header('Cache-Control', 'no-store, private');
    }
}
