<?php
namespace App\Http\Controllers\Tips;
use App\Models\{TipGrant,TipCheckoutIntent,TipReceipt};
use App\Services\Tips\{TipGuard,TipContext,ReadTipViewModel,IssueTipGrant,RevokeTipGrant,CreateTipCheckout,ReadEventTips};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class TipController {
    public function issue(Request $r,int $event,IssueTipGrant $s){return response()->json($s->execute($r->user(),$event,$r->all(),(string)$r->header('Idempotency-Key')),201);}
    public function revoke(Request $r,int $grant,RevokeTipGrant $s){return response()->json($s->execute($r->user(),$grant,$r->all(),(string)$r->header('Idempotency-Key')));}
    public function owner(Request $r,int $event,ReadEventTips $s){return response()->json($s->execute($r->user(),$event));}
    public function page(Request $r,ReadTipViewModel $s){
        TipGuard::transport($r);abort_if($r->user()!==null,403,'Abre el enlace como clienta en una sesión separada.');
        $lang=in_array($r->query('lang'),['es','en'],true)?$r->query('lang'):'es';
        $base=['lang'=>$lang,'lang_url'=>['es'=>'/portal/tip?lang=es','en'=>'/portal/tip?lang=en'],'test_mode'=>true,'preview'=>false,'first_name'=>'','review_url'=>null,'tip'=>['state'=>'closed'],'actions'=>[]];
        if($r->boolean('exchange')||!$r->cookie(TipContext::COOKIE))return response()->view('portal.tip-access',['vm'=>$base]);
        try{$vm=$s->read(TipContext::fromRequest($r));}
        catch(\Illuminate\Database\QueryException $e){throw $e;}
        catch(\App\Exceptions\RecordWriteRejected|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException){$vm=$base;}
        return response()->view('portal.tip',['vm'=>$vm]);
    }
    public function session(Request $r){
        TipGuard::transport($r);abort_if($r->user()!==null,403,'Abre el enlace como clienta en una sesión separada.');
        $d=$r->validate(['token'=>'required|string|regex:/^[A-Za-z0-9_-]{43}$/D']);$hint=TipGrant::where('token_hash',hash('sha256',$d['token']))->first();abort_unless($hint,401,'Acceso no válido.');$token=TipGuard::token();
        $expires=DB::transaction(function()use($hint,$token){[$g]=TipGuard::grant($hint->id);$expires=CarbonImmutable::now('UTC')->addDay()->min($g->expires_at);
            DB::table('tip_portal_sessions')->insert(['tip_grant_id'=>$g->id,'token_hash'=>hash('sha256',$token),'expires_at'=>$expires,'created_at'=>now(),'updated_at'=>now()]);return $expires;},3);
        return response()->json(['ok'=>true])->cookie(TipContext::COOKIE,$token,max(1,(int)floor(CarbonImmutable::now('UTC')->diffInSeconds($expires)/60)),'/portal',null,!app()->environment(['local','testing']),true,false,'lax');
    }
    public function logout(Request $r){TipGuard::transport($r);$token=$r->cookie(TipContext::COOKIE);if(is_string($token))DB::table('tip_portal_sessions')->where('token_hash',hash('sha256',$token))->delete();return response()->json(['ok'=>true])->withoutCookie(TipContext::COOKIE,'/portal');}
    public function read(Request $r,ReadTipViewModel $s){return response()->json($s->read(TipContext::fromRequest($r)));}
    public function checkout(Request $r,CreateTipCheckout $s){return response()->json($s->execute(TipContext::fromRequest($r),$r->all(),(string)$r->header('Idempotency-Key')),201);}
    public function status(Request $r,string $intent){$c=TipContext::fromRequest($r);return DB::transaction(function()use($c,$intent){[$g]=$c->authorize();$i=TipCheckoutIntent::whereKey($intent)->where('tip_grant_id',$g->id)->firstOrFail();$status=$i->status==='paid'&&!TipReceipt::where('tip_checkout_intent_id',$i->id)->exists()?'reconciliation_required':$i->status;return response()->json(['status'=>$status]);},3);}
}
