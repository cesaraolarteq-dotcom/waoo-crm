<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\Agreements\ReadConfirmedAgreement;
use Illuminate\Http\Request;
final class ConfirmedAgreementController extends Controller
{
    public function show(Request $r, int $event, ReadConfirmedAgreement $service)
    { return response()->json($service->execute($r->user(), $event))->header('Cache-Control', 'no-store, private'); }
}
