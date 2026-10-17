<?php
namespace App\Services\Portal;

use App\Models\{Event, PortalGrant, User};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Records\{RecordActor, RecordInput};
use App\Rules\ExplicitOffset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class IssuePortalGrant
{
    public function execute(User $actor, int $eventId, array $input): IssuedPortalGrant
    {
        Sandbox::require('sandbox_documents'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['customer_id' => 'required|integer|min:1', 'expires_at' => ['required','date','after:now','before_or_equal:'.now()->addDays(30)->toIso8601String(), new ExplicitOffset],
            'scopes' => 'sometimes|array|min:1|max:5', 'scopes.*' => ['required', 'distinct', Rule::in(['details.submit','contract.read','contract.sign','payments.read','checkout.create'])]]);
        try {
            return DB::transaction(function () use ($actor, $eventId, $data) {
                $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
                \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($eventId);
                abort_unless($event->customer_id === (int) $data['customer_id'], 404);
                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $grant = PortalGrant::create(['event_id' => $eventId, 'customer_id' => $event->customer_id, 'token_hash' => hash('sha256', $token),
                    'scopes' => $data['scopes'] ?? ['contract.read','contract.sign','payments.read','checkout.create'], 'expires_at' => CarbonImmutable::parse($data['expires_at'])->utc(), 'issued_by' => $actor->id]);
                $grant->forceFill(['encrypted_token' => PortalTokenVault::seal($grant, $token)])->save();
                Audit::recordFor($actor, 'portal.grant_issued', $grant, [], ['event_id' => $eventId, 'customer_id' => $event->customer_id, 'scopes' => $grant->scopes, 'expires_at' => $grant->expires_at->toIso8601String()]);
                if (in_array('details.submit', $grant->scopes, true)) {
                    \App\Models\QuickBookingRecord::where('event_id', $eventId)->update(['portal_grant_id' => $grant->id, 'portal_expires_at' => $grant->expires_at, 'updated_at' => now()]);
                    DB::table('payment_exceptions')->where('event_id', $eventId)->where('kind', 'portal_link_pending')->update(['state' => 'resolved', 'updated_at' => now()]);
                }
                return new IssuedPortalGrant($grant->id, Sandbox::origin().'/portal#token='.$token, $token, $grant->expires_at->toIso8601String());
            }, 3);
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Log::error('portal.link_issue_unavailable');
            throw \App\Exceptions\PortalLinkRejected::unavailable();
        }
    }
}
