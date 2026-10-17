<?php
namespace App\Http\Controllers\Webhooks;

use App\Services\Mail\ReceivePostmarkBounce;
use Illuminate\Http\Request;

final class PostmarkBounceController
{
    public function __invoke(Request $request, ReceivePostmarkBounce $receiver)
    {
        $receiver->execute($request);
        return response()->json(['received' => true])->header('Cache-Control', 'no-store, private');
    }
}
