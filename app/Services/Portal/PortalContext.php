<?php
namespace App\Services\Portal;

use App\Models\PortalGrant;
use App\Services\Finance\Sandbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final readonly class PortalContext
{
    private function __construct(public int $grantId, public int $eventId, public int $customerId) {}
    public static function fromRequest(Request $request): self
    {
        Sandbox::require('sandbox_documents');
        self::requireTransport($request);
        $row = PortalGrant::find($request->session()->get('portal_grant_id'));
        if (! $row || $row->revoked_at || ! $row->expires_at->isFuture()) throw new AuthorizationException('Este acceso venció o fue revocado.');
        return new self($row->id, $row->event_id, $row->customer_id);
    }
    public function authorize(int $eventId, string $scope, bool $lock = false): PortalGrant
    {
        Sandbox::require('sandbox_documents');
        $query = PortalGrant::whereKey($this->grantId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row || $row->revoked_at || ! $row->expires_at->isFuture() || $row->event_id !== $eventId || $row->customer_id !== $this->customerId || ! in_array($scope, $row->scopes, true)) {
            throw new AuthorizationException('Este acceso no permite esa operación.');
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
