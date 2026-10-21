<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\Booking\{HoldQuickBookingCostumes, ReadQuickBookingCostumeHold, ReleaseQuickBookingCostumes};
use Illuminate\Http\Request;
final class QuickCostumeHoldController extends Controller
{
    public function show(Request $r, int $event, ReadQuickBookingCostumeHold $s) { return $this->result($s->execute($r->user(), $event)); }
    public function attempt(Request $r, int $event, HoldQuickBookingCostumes $s) { return $this->result($s->execute($r->user(), $event, $r->all(), (string)$r->header('Idempotency-Key'))); }
    public function release(Request $r, int $event, ReleaseQuickBookingCostumes $s) { return $this->result($s->execute($r->user(), $event, $r->all(), (string)$r->header('Idempotency-Key'))); }
    private function result(array $v) { return response()->json($v)->header('Cache-Control', 'no-store, private'); }
}
