<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\{Contract, Event, PortalGrant, SignatureEvidence};
use App\Services\Documents\{AcceptSignature, ArtifactStore, InHouseSignatureProvider, SignatureRequestContext};
use App\Services\Finance\{FinanceSummary, Sandbox};
use App\Services\Portal\PortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PortalController extends Controller
{
    public function session(Request $request)
    {
        Sandbox::require('sandbox_documents');
        PortalContext::requireTransport($request);
        $data = $request->validate(['token' => 'required|string|size:43']);
        $grant = PortalGrant::where('token_hash', hash('sha256', $data['token']))->first();
        abort_unless($grant && ! $grant->revoked_at && $grant->expires_at->isFuture(), 403, 'Este enlace venció o no es válido.');
        $request->session()->regenerate(true); $request->session()->put('portal_grant_id', $grant->id); $request->session()->regenerateToken();
        return response()->json(['csrf_token' => csrf_token(), 'expires_at' => $grant->expires_at, 'scopes' => $grant->scopes])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
    private function scopedContract(Request $request, string $id, string $scope = 'contract.read'): Contract
    {
        $client = $request->attributes->get('portal_context'); $client->authorize($client->eventId, $scope);
        return Contract::whereKey($id)->where('event_id', $client->eventId)->where('signer_customer_id', $client->customerId)->whereIn('state', ['issued','signed'])->firstOrFail();
    }
    public function event(Request $request, FinanceSummary $finance)
    {
        $client = $request->attributes->get('portal_context'); $grant = $client->authorize($client->eventId, 'payments.read');
        $event = Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->firstOrFail();
        $summary = $finance->forEvent($event);
        if ($summary['financial_revision']) {
            $discounts=array_map(fn($d)=>['label'=>$d['label'],'amount_cents'=>$d['amount_cents']],$summary['financial_revision']->discounts_snapshot??[]);
            $summary['financial_revision'] = [...$summary['financial_revision']->only(['id','revision','currency','total_cents','deposit_target_cents','balance_due_at','discount_cents']),'discounts'=>$discounts];
        }
        $revision = \App\Models\FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->first();
        $services = collect($revision?->lines_snapshot ?? [])->map(fn ($line) => [
            'name' => $line['name'] ?? null, 'duration_minutes' => $line['duration_minutes'] ?? null,
            'amount_cents' => $line['amount_cents'], 'extra_characters' => (int) ($line['extra_characters'] ?? 0), 'characters' => collect($line['characters'] ?? [])->map(fn ($character) => [
                'name' => $character['name'], 'quantity' => $character['quantity'],
            ])->all(),
        ])->all();
        $current = null;
        if (in_array('contract.read', $grant->scopes, true)) {
            $current = Contract::where('event_id', $event->id)->where('signer_customer_id', $client->customerId)
                ->whereIn('state', ['issued','signed'])->orderByDesc('revision')->first()?->only(['id','state','version','revision','issued_at','signed_at','presented_pdf_sha256','final_pdf_sha256']);
        }
        return response()->json(['event' => $event->only(['id','title','starts_at','ends_at','timezone','address','status']),
            'services' => $services, 'finance' => $summary, 'contract' => $current,
            'details' => in_array('details.submit', $grant->scopes, true) ? \App\Services\Booking\ClientDetailsState::portal($event) : null]);
    }
    public function show(Request $request, string $contract)
    {
        $row = $this->scopedContract($request, $contract);
        return response()->json(['contract' => $row->only(['id','state','version','revision','issued_at','signed_at','presented_pdf_sha256','final_pdf_sha256']), 'consent' => InHouseSignatureProvider::consent($request->query('lang') === 'es' ? 'es' : 'en'), 'final_pdf_ready' => (bool) $row->final_pdf_path]);
    }
    public function pdf(Request $request, string $contract, ArtifactStore $artifacts)
    {
        $row = $this->scopedContract($request, $contract);
        $kind = $request->validate(['artifact' => 'sometimes|in:presented,final'])['artifact'] ?? 'presented';
        abort_unless($row->{$kind.'_pdf_path'} && $row->{$kind.'_pdf_sha256'}, 404);
        $bytes = $artifacts->read($row->{$kind.'_pdf_path'}, $row->{$kind.'_pdf_sha256'});
        if ($kind === 'presented') DB::table('contract_presentations')->insertOrIgnore(['contract_id' => $row->id, 'portal_grant_id' => $request->attributes->get('portal_context')->grantId, 'presented_pdf_sha256' => $row->presented_pdf_sha256, 'served_at' => now()]);
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="contract-'.$row->id.'.pdf"']);
    }
    public function sign(Request $request, string $contract, AcceptSignature $service)
    {
        $evidence = $service->execute($request->attributes->get('portal_context'), $contract, $request->all(), SignatureRequestContext::fromRequest($request), (string) $request->header('Idempotency-Key'));
        return response()->json(['signature' => $evidence->only(['id','contract_id','accepted_at','presented_pdf_sha256','identity_method','consent_version']), 'final_pdf_ready' => (bool) Contract::findOrFail($contract)->final_pdf_path]);
    }
    public function signature(Request $request, string $contract)
    {
        $row = $this->scopedContract($request, $contract);
        $evidence = SignatureEvidence::where('contract_id', $row->id)->firstOrFail();
        return response()->json(['signature' => $evidence->only(['id','contract_id','accepted_at','presented_pdf_sha256','identity_method','consent_version']), 'final_pdf_ready' => (bool) $row->final_pdf_path]);
    }
}
