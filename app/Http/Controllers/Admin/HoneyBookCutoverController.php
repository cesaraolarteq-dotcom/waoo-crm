<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Imports\{PrepareHoneyBookCutoverReconciliation, ReadHoneyBookCutoverReadiness, ReadHoneyBookCutoverReview, SaveHoneyBookCutoverReview};
use Illuminate\Http\Request;

final class HoneyBookCutoverController extends Controller
{
    public function index(Request $r, ReadHoneyBookCutoverReadiness $read) { return $read->execute($r->user()); }
    public function show(Request $r, int $event, ReadHoneyBookCutoverReview $read) { return $read->execute($r->user(), $event); }
    public function review(Request $r, int $event, SaveHoneyBookCutoverReview $save)
    {
        return ['review' => $save->execute($r->user(), $event, $r->all(), (string)$r->header('Idempotency-Key'))];
    }
    public function prepare(Request $r, PrepareHoneyBookCutoverReconciliation $prepare)
    {
        return ['reconciliation' => $prepare->execute($r->user(), $r->all(), (string)$r->header('Idempotency-Key')), 'transfer_available' => false, 'sending_enabled' => false];
    }
}
