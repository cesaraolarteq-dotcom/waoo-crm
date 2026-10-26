<?php
namespace App\Services\Portal;

use App\Models\PortalGrant;
use App\Services\Finance\Sandbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final readonly class PortalContext
{
    private function __construct(public int $grantId, public int $eventId, public int $customerId, public ?string $ip, public string $userAgent, public bool $adminAuthenticated) {}
    public static function fromRequest(Request $request): self
    {
        Sandbox::require('sandbox_documents');
        self::requireTransport($request);
        $row = PortalGrant::find($request->session()->get('portal_grant_id'));
        if (! $row || $row->revoked_at || ! $row->expires_at->isFuture()) throw new AuthorizationException('Este acceso venció o fue revocado.');
        return new self($row->id, $row->event_id, $row->customer_id, $request->ip(), mb_substr((string) $request->userAgent(), 0, 2000), $request->user() !== null);
    }
    public function authorize(int $eventId, string $scope, bool $lock = false): PortalGrant
    {
        Sandbox::require('sandbox_documents');
        $query = PortalGrant::whereKey($this->grantId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row || $row->revoked_at || ! $row->expires_at->isFuture() || $row->event_id !== $eventId || $row->customer_id !== $this->customerId || ! self::allows($row, $scope)) {
            throw new AuthorizationException('Este acceso no permite esa operación.');
        }
        if (in_array($scope, ['payments.read', 'checkout.create'], true)) {
            \App\Services\Responsibility\RequireCrmResponsibility::forEvent($eventId, $lock);
            $booking = \App\Services\Booking\QuickPaymentProtocol::booking($eventId);
            if ($scope === 'payments.read' && $booking && $booking->portal_grant_id === $row->id && in_array('quick_booking.pay', $row->scopes, true)) {
                \App\Services\Booking\QuickPaymentProtocol::gate();
                \App\Services\Booking\QuickPaymentProtocol::verify($booking, \App\Models\Event::findOrFail($eventId));
            } else \App\Services\Booking\QuickStripeLeases::assertNoActive($eventId);
        }
        return $row;
    }
    /**
     * Owner 2026-10-26: a quick-booking link pays the deposit before signing. Once its contract is signed and the deposit is
     * fully received, the same link may pay the balance (card or Zelle notice); before that it never can, so a pending
     * Zelle deposit cannot be paid twice. Other links keep exactly their issued scopes.
     */
    public static function allows(PortalGrant $grant, string $scope): bool
    {
        if (in_array($scope, $grant->scopes, true)) return true;
        if ($scope !== 'checkout.create' || ! in_array('quick_booking.pay', $grant->scopes, true)) return false;
        $signed = \App\Models\Contract::where('event_id', $grant->event_id)->where('signer_customer_id', $grant->customer_id)->where('state', 'signed')->exists();
        if (! $signed) return false;
        $finance = app(\App\Services\Finance\FinanceSummary::class)->forEvent(\App\Models\Event::findOrFail($grant->event_id));
        return ($finance['deposit_shortfall_cents'] ?? 1) === 0;
    }
    public static function requireTransport(Request $request): void
    {
        if (app()->environment('staging') && (!$request->isSecure() || config('session.secure') !== true)) {
            throw new AuthorizationException('El portal de pruebas requiere HTTPS y una cookie de sesión segura.');
        }
    }

}
