<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Extras\EventExtras;
use Illuminate\Http\Request;

// EXTRAS-01: the client accepts the annex of one extra (checkbox) from her link.
final class ExtrasController extends Controller
{
    public function accept(Request $request, EventExtras $service)
    {
        abort_unless(config('waoo.sandbox_extras', false), 404);
        $data = $request->validate(['offer' => 'required|string|max:40', 'annex_sha256' => 'required|string|size:64', 'accept' => 'accepted', 'lang' => 'nullable|in:es,en']);
        $extra = $service->accept($request->attributes->get('portal_context'), $data['offer'], $data['annex_sha256'], $data['lang'] ?? 'es');

        return response()->json(['extra' => $extra->only(['id', 'kind', 'amount_cents', 'accepted_at'])], 201);
    }
}
