<?php
namespace App\Http\Controllers\Admin;
use App\Services\Portal\IssueOpeningPortalGrant;
use Illuminate\Http\Request;
final class OpeningPortalGrantController
{
    public function store(Request $r,int $event,IssueOpeningPortalGrant $service)
    {return response()->json($service->execute($r->user(),$event,$r->all(),(string)$r->header('Idempotency-Key')),201)->header('Cache-Control','no-store, private');}
}
