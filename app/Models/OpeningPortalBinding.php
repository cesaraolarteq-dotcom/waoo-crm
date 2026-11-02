<?php
namespace App\Models;
class OpeningPortalBinding extends ImmutableRecord
{
    protected $primaryKey='portal_grant_id';public $incrementing=false;protected $guarded=[];protected $hidden=['payload'];
    protected function casts():array{return ['payload'=>'encrypted:array'];}
    public static function facts(PortalGrant $g):array
    {
        return ['portal_grant_id'=>(int)$g->id,'event_id'=>(int)$g->event_id,'customer_id'=>(int)$g->customer_id,
            'binding_id'=>(int)$g->cutover_financial_binding_id,'snapshot_sha256'=>$g->opening_snapshot_sha256,
            'expires_at'=>$g->expires_at->toIso8601String(),'scopes'=>$g->scopes,'token_sha256'=>$g->token_hash,'issued_by'=>(int)$g->issued_by];
    }
}
