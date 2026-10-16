<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Services\Finance\{CreateCheckoutIntent, Sandbox};
use Illuminate\Http\Request;

final class CheckoutController extends Controller
{
    public function create(Request $request, CreateCheckoutIntent $service)
    {
        $client = $request->attributes->get('portal_context');
        return response()->json(\App\Http\Controllers\Admin\CheckoutController::data($service->execute($client, $client->eventId, $request->all(), (string) $request->header('Idempotency-Key'))), 201);
    }
    public function show(Request $request, string $intent)
    {
        Sandbox::require(); $client = $request->attributes->get('portal_context'); $client->authorize($client->eventId, 'checkout.create');
        $row = PaymentIntent::whereKey($intent)->where('event_id', $client->eventId)->firstOrFail();
        return response()->json(\App\Http\Controllers\Admin\CheckoutController::data($row));
    }
}
