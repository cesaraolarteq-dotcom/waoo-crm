<?php
namespace App\Services\Portal;

use App\Models\PortalGrant;
use App\Services\Finance\Sandbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final readonly class PortalContext
{
    private function __construct(public int $grantId, public int $eventId, public int $customerId, public ?string $ip, public string $userAgent, public bool $adminAuthenticated, public ?int $openingBindingId = null, public ?string $openingSnapshot = null) {}
    public static function fromRequest(Request $request): self
    {
        Sandbox::require('sandbox_documents');
        self::requireTransport($request);
        $row = PortalGrant::find($request->session()->get('portal_grant_id'));
        if (! $row || $row->revoked_at || ! $row->expires_at->isFuture()) throw new AuthorizationException('Este acceso venció o fue revocado.');
        $binding=$request->session()->get('portal_opening_binding_id');$snapshot=$request->session()->get('portal_opening_snapshot_sha256');
        if($row->cutover_financial_binding_id!==null||$binding!==null){
            if(!is_int($binding)||(int)$row->cutover_financial_binding_id!==$binding||!is_string($snapshot)
                ||!is_string($row->opening_snapshot_sha256)||!hash_equals($snapshot,$row->opening_snapshot_sha256))throw new AuthorizationException('Este acceso original cambió. Abre el enlace de nuevo.');
        }
        return new self($row->id, $row->event_id, $row->customer_id, $request->ip(), mb_substr((string) $request->userAgent(), 0, 2000), $request->user() !== null, $binding, $snapshot);
    }
    public function authorize(int $eventId, string $scope, bool $lock = false): PortalGrant
    {
        Sandbox::require('sandbox_documents');
        $query = PortalGrant::whereKey($this->grantId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row || $row->revoked_at || ! $row->expires_at->isFuture() || $row->event_id !== $eventId || $row->customer_id !== $this->customerId || ! in_array($scope, $row->scopes, true)) {
            throw new AuthorizationException('Este acceso no permite esa operación.');
        }
        if ($this->openingBindingId !== null && ((int)$row->cutover_financial_binding_id !== $this->openingBindingId
            || !is_string($row->opening_snapshot_sha256) || !hash_equals($this->openingSnapshot, $row->opening_snapshot_sha256))) throw new AuthorizationException('El acceso original cambió.');
        if (($row->cutover_financial_binding_id !== null || $scope === 'agreement.read')
            && ($row->cutover_financial_binding_id === null || $scope !== 'agreement.read' || $row->scopes !== ['agreement.read'])) {
            throw new AuthorizationException('Este enlace solo permite consultar el acuerdo original.');
        }
        if (in_array($scope, ['payments.read', 'checkout.create'], true)) {
            \App\Services\Responsibility\RequireCrmResponsibility::forEvent($eventId, $lock);
        }
        return $row;
    }
    public static function requireTransport(Request $request): void
    {
        if (app()->environment('staging') && (!$request->isSecure() || config('session.secure') !== true)) {
            throw new AuthorizationException('El portal de pruebas requiere HTTPS y una cookie de sesión segura.');
        }
    }

}
