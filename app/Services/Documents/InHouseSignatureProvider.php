<?php
namespace App\Services\Documents;

use App\Models\{Contract, ContractTemplateVersion, SignatureEvidence};
use App\Services\Portal\PortalContext;

final class InHouseSignatureProvider implements SignatureProvider
{
    public const CONSENT_VERSION = 'esign-consent-v2-2026-10-20-en';
    public const CONSENT = 'I agree to sign electronically and to receive this contract electronically.';
    public static function consent(string $locale): array
    {
        $locale = $locale === 'es' ? 'es' : 'en';
        return ['version' => 'esign-consent-v2-2026-10-20-'.$locale,
            'text' => \App\Support\ApprovedClientTexts::text('esign_consent', $locale),
            'intent' => \App\Support\ApprovedClientTexts::text('esign_intent', $locale)];
    }
    public static function consentForVersion(string $version): array
    {
        foreach (['es','en'] as $locale) {
            $consent = self::consent($locale);
            if ($consent['version'] === $version) return $consent;
        }
        throw \Illuminate\Validation\ValidationException::withMessages(['consent_version' => 'Vuelve a abrir el contrato para revisar el consentimiento vigente.']);
    }
    public function capture(Contract $contract, PortalContext $client, array $input, SignatureRequestContext $request): SignatureEvidence
    {
        $template = ContractTemplateVersion::findOrFail($contract->template_version_id);
        $consent = self::consentForVersion($input['consent_version']);
        $text = $consent['text']."\n".$consent['intent'];
        return SignatureEvidence::create(['contract_id' => $contract->id, 'portal_grant_id' => $client->grantId, 'customer_id' => $client->customerId,
            'presentation_id' => \Illuminate\Support\Facades\DB::table('contract_presentations')->where('contract_id', $contract->id)->where('portal_grant_id', $client->grantId)->where('presented_pdf_sha256', $contract->presented_pdf_sha256)->value('id'), 'signer_name' => $input['signer_name'], 'signer_email' => $contract->signer_email, 'identity_method' => 'scoped_link_email_unverified',
            'consent_version' => $consent['version'], 'consent_text' => $text, 'consent_sha256' => hash('sha256', $text),
            'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'template_source_sha256' => $template->source_sha256, 'template_terms_sha256' => $template->terms_sha256,
            'ip' => $request->ip, 'user_agent' => $request->userAgent, 'accepted_at' => now()->startOfSecond()]);
    }
}
