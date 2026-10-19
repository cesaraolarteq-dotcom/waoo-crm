<?php
namespace App\Services\Staff;

use App\Models\StaffAccessGrant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class StaffContext
{
    public const COOKIE = 'waoo_staff_session';
    private function __construct(public int $sessionId, public int $grantId, public int $staffId, public int $resourceId, public string $locale, private string $sessionHash) {}

    public static function fromRequest(Request $request): self
    {
        StaffGuard::transport($request);
        abort_if($request->user() !== null || $request->session()->has('portal_grant_id'),403,'Abre el enlace en una sesión separada.');
        $token=$request->cookie(self::COOKIE);
        abort_unless(is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/D',$token),401,'Este acceso no es válido.');
        $hash=hash('sha256',$token);
        $session=DB::table('staff_portal_sessions')->where('token_hash',$hash)->first();
        abort_unless($session,401,'Este acceso no es válido.');
        return DB::transaction(function () use ($session,$hash,$request) {
            try { [$grant,$identity,$resource]=StaffGuard::grant($session->staff_access_grant_id); }
            catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Database\Eloquent\ModelNotFoundException) { abort(401,'Este acceso no es válido.'); }
            $current=DB::table('staff_portal_sessions')->where('id',$session->id)->lockForUpdate()->first();
            abort_unless($current && hash_equals($current->token_hash,$hash) && CarbonImmutable::parse($current->expires_at,'UTC')->isFuture(),401,'Este acceso no es válido.');
            $lang=in_array($request->query('lang'),['es','en'],true)?$request->query('lang'):$identity->locale;
            return new self($current->id,$grant->id,$identity->id,$resource->id,$lang,$hash);
        },3);
    }

    // Call inside the read transaction; revalidate resource, grant and session every time.
    public function authorize(): array
    {
        \App\Services\Finance\Sandbox::require('sandbox_staff');
        try { [$grant,$identity,$resource]=StaffGuard::grant($this->grantId); }
        catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Database\Eloquent\ModelNotFoundException) { abort(401,'Este acceso no es válido.'); }
        $session=DB::table('staff_portal_sessions')->where('id',$this->sessionId)->lockForUpdate()->first();
        abort_unless($session && hash_equals($session->token_hash,$this->sessionHash)
            && (int)$session->staff_access_grant_id===$grant->id && $identity->id===$this->staffId && $resource->id===$this->resourceId
            && CarbonImmutable::parse($session->expires_at,'UTC')->isFuture(),401,'Este acceso no es válido.');
        return [$identity,$resource];
    }
}
