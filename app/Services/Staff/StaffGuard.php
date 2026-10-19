<?php
namespace App\Services\Staff;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Resource, StaffIdentity, StaffAccessGrant, User};
use App\Services\Finance\Sandbox;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

final class StaffGuard
{
    public static function owner(User $actor): User { self::transport(request()); return RecordActor::authorize($actor); }
    public static function version(array $input): int
    {
        return (int) RecordInput::validate($input, ['expected_version'=>'required|integer|min:1'])['expected_version'];
    }
    public static function transport(Request $r): void
    {
        Sandbox::require('sandbox_staff');
        if (!$r->isMethodSafe() && $r->headers->has('Origin')) {
            $parts=parse_url(Sandbox::origin());
            $origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
            abort_unless($r->header('Origin')===$origin,403);
        }
        abort_unless(app()->environment(['local','testing']) || ($r->isSecure() && config('session.secure') === true), 403);
    }
    // All paths acquire resource -> identity -> grant; never lock a grant before its resource.
    public static function identity(int $id, bool $requireActive = true): array
    {
        $hint = StaffIdentity::findOrFail($id);
        $resource = Resource::whereKey($hint->resource_id)->lockForUpdate()->firstOrFail();
        $identity = StaffIdentity::whereKey($id)->lockForUpdate()->firstOrFail();
        RecordWriteRejected::unless($resource->kind === 'staff', 409, 'La ficha no es personal.');
        if ($requireActive) RecordWriteRejected::unless($identity->active && $resource->status === 'active', 409, 'Esta persona está desactivada.');
        return [$identity, $resource];
    }
    public static function grant(int $id, bool $requireActive = true): array
    {
        $hint = StaffAccessGrant::findOrFail($id);
        [$identity,$resource] = self::identity($hint->staff_identity_id, $requireActive);
        $grant = StaffAccessGrant::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($requireActive) self::live($grant);
        return [$grant,$identity,$resource];
    }
    public static function live(StaffAccessGrant $grant): void
    {
        RecordWriteRejected::unless($grant->revoked_at === null && (int)$grant->active_staff_id === (int)$grant->staff_identity_id, 409, 'Este enlace fue apagado o sustituido.');
    }
    public static function revoke(StaffAccessGrant $grant): void
    {
        $grant->fill(['revoked_at'=>now(),'active_staff_id'=>null,'encrypted_token'=>null,'version'=>$grant->version+1])->save();
    }
    public static function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    public static function create(StaffIdentity $identity, User $actor): StaffAccessGrant
    {
        $token = self::token();
        $grant = StaffAccessGrant::create(['staff_identity_id'=>$identity->id,'active_staff_id'=>$identity->id,'token_hash'=>hash('sha256',$token),'created_by'=>$actor->id]);
        $grant->encrypted_token = Crypt::encryptString(json_encode(['grant_id'=>$grant->id,'staff_id'=>$identity->id,'token'=>$token], JSON_THROW_ON_ERROR));
        $grant->save();
        return $grant;
    }
    public static function envelope(StaffAccessGrant $grant, StaffIdentity $identity): array
    {
        self::live($grant);
        try {
            $sealed = json_decode(Crypt::decryptString($grant->encrypted_token), true, flags: JSON_THROW_ON_ERROR);
            $token = $sealed['token'] ?? null;
            if (($sealed['grant_id']??null) !== $grant->id || ($sealed['staff_id']??null) !== $identity->id
                || !is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$token) || !hash_equals($grant->token_hash,hash('sha256',$token))) throw new \RuntimeException();
        } catch (\Throwable) { throw new RecordWriteRejected(409, 'No se puede recuperar este enlace. Cámbialo explícitamente.'); }
        return ['staff_id'=>$identity->id,'version'=>$identity->version,'grant'=>['id'=>$grant->id,'version'=>$grant->version],
            'link'=>Sandbox::origin().'/staff#'.$token,'phone'=>$identity->phone];
    }
    public static function disableResource(Resource $resource): void
    {
        // Called inside SaveResource's transaction, with its resource already locked.
        if ($resource->kind !== 'staff' || $resource->status === 'active') return;
        $identity = StaffIdentity::where('resource_id',$resource->id)->lockForUpdate()->first();
        if (!$identity) return;
        foreach (StaffAccessGrant::where('active_staff_id',$identity->id)->lockForUpdate()->get() as $grant) self::revoke($grant);
    }
}
