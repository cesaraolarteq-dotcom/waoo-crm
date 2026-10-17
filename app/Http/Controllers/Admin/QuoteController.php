<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\Quotes\{CreateQuote, ReadQuotes, ReviseQuote};
use Illuminate\Http\Request;

final class QuoteController extends Controller
{
    public function index(Request $request, ReadQuotes $service) { return response()->json($service->index($request->user(), $request->query()))->header('Cache-Control', 'no-store, private'); }
    public function show(Request $request, int $quote, ReadQuotes $service) { return response()->json($service->show($request->user(), $quote))->header('Cache-Control', 'no-store, private'); }
    public function create(Request $request, CreateQuote $service)
    {
        $r = $service->execute($request->user(), $request->all(), (string) $request->header('Idempotency-Key'));
        return response()->json(['quote' => Quote::findOrFail($r->quote_id), 'revision' => $r], 201)->header('Cache-Control', 'no-store, private');
    }
    public function revise(Request $request, int $quote, ReviseQuote $service)
    {
        $r = $service->execute($request->user(), $quote, $request->all(), (string) $request->header('Idempotency-Key'));
        return response()->json(['quote' => Quote::findOrFail($r->quote_id), 'revision' => $r], 201)->header('Cache-Control', 'no-store, private');
    }
    public function send(Request $request,int $quote,\App\Services\Quotes\SendQuote $service)
    {
        return response()->json($service->execute($request->user(),$quote,$request->all(),(string)$request->header('Idempotency-Key')),201)->header('Cache-Control','no-store, private');
    }
    public function convert(Request $request,int $quote,\App\Services\Quotes\ConvertAcceptedQuote $service)
    {
        return response()->json(['financial_revision'=>$service->execute($request->user(),$quote,$request->all(),(string)$request->header('Idempotency-Key'))],201)->header('Cache-Control','no-store, private');
    }
}
