<?php
namespace App\Services\Portal;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, ZelleNotice};
use App\Rules\ExplicitOffset;
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Records\RecordInput;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SubmitZelleNotice
{
    public function execute(PortalContext $client, array $input, string $key): ZelleNotice
    {
        Sandbox::require(); Sandbox::require('sandbox_documents');
        if ($client->adminAuthenticated) throw new AuthorizationException('Solo la clienta puede declarar su envío.');
        $data = RecordInput::validate($input, ['financial_revision_id' => 'required|integer|min:1', 'amount_cents' => 'required|integer|min:1|max:100000000',
            'reference' => 'nullable|string|min:3|max:255', 'payer_name' => 'nullable|string|max:120',
            'sent_at' => ['nullable','date','before_or_equal:now',new ExplicitOffset], 'note' => 'nullable|string|max:1000']);
        $data['financial_revision_id'] = (int) $data['financial_revision_id']; $data['amount_cents'] = (int) $data['amount_cents'];
        foreach (['reference','payer_name','note'] as $field) $data[$field] ??= null;
        $data['sent_at'] = isset($data['sent_at']) ? CarbonImmutable::parse($data['sent_at'])->utc()->startOfSecond()->toIso8601String() : null;
        $scope = "customer:{$client->customerId}:event:{$client->eventId}:zelle-notice"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($client, $data, $scope, $hash, $key) {
            $client->authorize($client->eventId, 'checkout.create', true);
            $event = Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return ZelleNotice::findOrFail($saved->result_id);
            $revision = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->firstOrFail();
            RecordWriteRejected::unless($revision->id === $data['financial_revision_id'] && ($event->status !== 'draft' || $event->version === $revision->event_version), 409, 'Los importes cambiaron. Recarga antes de declarar el envío.');
            $notice = ZelleNotice::create([...$data, 'event_id' => $event->id, 'customer_id' => $client->customerId, 'portal_grant_id' => $client->grantId,
                'ip' => $client->ip, 'user_agent' => $client->userAgent, 'submitted_at' => now()]);
            Audit::recordSystem('portal.zelle_notice_submitted', $notice, [], ['event_id' => $event->id, 'customer_id' => $client->customerId, 'portal_grant_id' => $client->grantId, 'amount_cents' => $notice->amount_cents]);
            MutationKey::store($scope, $key, $hash, 'zelle_notice', $notice->id);
            return $notice;
        }, 3);
    }
}
