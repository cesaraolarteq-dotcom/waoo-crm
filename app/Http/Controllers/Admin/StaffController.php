<?php
namespace App\Http\Controllers\Admin;
use App\Services\Staff\{SaveStaffIdentity, ReadStaffLinks, IssueStaffGrant, RevealStaffGrantLink, RotateStaffGrant, RevokeStaffGrant, SaveStaffJobInstructions};
use Illuminate\Http\Request;
final class StaffController
{
    public function identities(Request $r,SaveStaffIdentity $s) { return response()->json($s->execute($r->user(),$r->all(),(string)$r->header('Idempotency-Key'))); }
    public function index(Request $r,ReadStaffLinks $s) { return response()->json($s->execute($r->user(),$r->query())); }
    public function issue(Request $r,int $staff,IssueStaffGrant $s) { return response()->json($s->execute($r->user(),$staff,$r->all(),(string)$r->header('Idempotency-Key')),201); }
    public function reveal(Request $r,int $grant,RevealStaffGrantLink $s) { return response()->json($s->execute($r->user(),$grant,$r->all())); }
    public function rotate(Request $r,int $grant,RotateStaffGrant $s) { return response()->json($s->execute($r->user(),$grant,$r->all(),(string)$r->header('Idempotency-Key'))); }
    public function revoke(Request $r,int $grant,RevokeStaffGrant $s) { return response()->json($s->execute($r->user(),$grant,$r->all(),(string)$r->header('Idempotency-Key'))); }
    public function instructions(Request $r,int $event,SaveStaffJobInstructions $s) { return response()->json($s->execute($r->user(),$event,$r->all(),(string)$r->header('Idempotency-Key'))); }
}
