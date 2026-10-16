<?php
namespace App\Services\Documents;

use App\Models\{Contract, ContractTemplateVersion, SignatureEvidence};
use App\Services\Portal\PortalContext;

final class InHouseSignatureProvider implements SignatureProvider
{
    public const CONSENT_VERSION = 'esign-consent-v1';
    public const CONSENT = 'I have read and accept the contract. My electronic signature is as valid as a handwritten one.';
    public function capture(Contract $contract, PortalContext $client, array $input, SignatureRequestContext $request): SignatureEvidence
    {
        $template = ContractTemplateVersion::findOrFail($contract->template_version_id);
        return SignatureEvidence::create(['contract_id' => $contract->id, 'portal_grant_id' => $client->grantId, 'customer_id' => $client->customerId,
            'presentation_id' => \Illuminate\Support\Facades\DB::table('contract_presentations')->where('contract_id', $contract->id)->where('portal_grant_id', $client->grantId)->where('presented_pdf_sha256', $contract->presented_pdf_sha256)->value('id'), 'signer_name' => $input['signer_name'], 'signer_email' => $contract->signer_email, 'identity_method' => 'scoped_link_email_unverified',
            'consent_version' => self::CONSENT_VERSION, 'consent_text' => self::CONSENT, 'consent_sha256' => hash('sha256', self::CONSENT),
            'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'template_source_sha256' => $template->source_sha256, 'template_terms_sha256' => $template->terms_sha256,
            'ip' => $request->ip, 'user_agent' => $request->userAgent, 'accepted_at' => now()->startOfSecond()]);
    }
}
