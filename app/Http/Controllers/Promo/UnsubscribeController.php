<?php
namespace App\Http\Controllers\Promo;

use App\Models\Customer;
use App\Services\Audit;
use Illuminate\Http\Request;

/**
 * CAN-SPAM unsubscribe for promotional emails (ANNIV-01). The link is signed per client and never expires. GET only shows
 * the page (mail scanners prefetch links); the button POSTs. Nothing about the client is shown back.
 */
final class UnsubscribeController
{
    public function show(Request $request, Customer $customer)
    {
        return response()->view('promo.unsubscribe', ['done' => $customer->promo_opt_out_at !== null, 'action' => $request->fullUrl()])
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function store(Request $request, Customer $customer)
    {
        if ($customer->promo_opt_out_at === null) {
            $customer->forceFill(['promo_opt_out_at' => now()])->save();
            Audit::recordSystem('customer.promo_unsubscribed', $customer, [], ['promo_opt_out_at' => $customer->promo_opt_out_at?->toIso8601String()]);
        }
        return response()->view('promo.unsubscribe', ['done' => true, 'action' => null])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
