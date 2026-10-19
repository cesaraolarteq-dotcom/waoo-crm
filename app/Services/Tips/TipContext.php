<?php
namespace App\Services\Tips;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final readonly class TipContext {
    public const COOKIE='waoo_tip_session';
    private function __construct(public int $grantId,private int $sessionId,private string $hash,public string $locale,public ?string $checkoutId){}
    public static function fromRequest(Request $r):self {
        TipGuard::transport($r);abort_if($r->user()!==null,403,'Abre el enlace como clienta en una sesión separada.');
        $token=$r->cookie(self::COOKIE);abort_unless(is_string($token)&&preg_match('/^[A-Za-z0-9_-]{43}$/D',$token),401,'Acceso no válido.');$hash=hash('sha256',$token);
        $s=DB::table('tip_portal_sessions')->where('token_hash',$hash)->first();abort_unless($s,401,'Acceso no válido.');
        $g=\App\Models\TipGrant::find($s->tip_grant_id);abort_unless($g,401,'Acceso no válido.');
        $locale=in_array($r->query('lang'),['es','en'],true)?$r->query('lang'):$g->locale;
        $checkout=$r->query('checkout');abort_if($checkout!==null&&(!is_string($checkout)||!Str::isUuid($checkout)),422);
        return new self($g->id,$s->id,$hash,$locale,$checkout);
    }
    public function authorize():array {
        TipGuard::enabled();[$g,$e,$f]=TipGuard::grant($this->grantId);
        $s=DB::table('tip_portal_sessions')->where('id',$this->sessionId)->lockForUpdate()->first();
        abort_unless($s&&(int)$s->tip_grant_id===$g->id&&hash_equals($s->token_hash,$this->hash)&&CarbonImmutable::parse($s->expires_at,'UTC')->isFuture(),401,'Acceso no válido.');
        return [$g,$e,$f];
    }
}
