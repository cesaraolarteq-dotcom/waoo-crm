<?php

namespace Tests\Unit;

use App\Models\SignatureEvidence;
use App\Services\Documents\PrintRenderer;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SignedContractLookTest extends TestCase
{
    private function evidence(): SignatureEvidence
    {
        return (new SignatureEvidence())->forceFill(['signer_name' => 'María <González>', 'signer_email' => 'maria@example.test',
            'accepted_at' => CarbonImmutable::parse('2026-11-14 20:05:00', 'UTC'), 'consent_text' => 'Acepto', 'consent_version' => 'esign-consent-v2',
            'presented_pdf_sha256' => str_repeat('a', 64), 'template_source_sha256' => str_repeat('b', 64), 'template_terms_sha256' => str_repeat('c', 64),
            'ip' => '203.0.113.7', 'user_agent' => 'Safari']);
    }

    public function test_slots_are_filled_escaped_and_only_inside_the_signature_table(): void
    {
        $presented = '<html><body><div class="chip">Awaiting signature</div><p>Terms keep <div class="line"></div><span class="muted">Signed </span> as text.</p>'
            .'<table class="sig"><tr><td><b>Provider</b><br>Waoo Party LLC<div class="line">Waoo Party</div><span class="muted">Provider acceptance pending</span></td>'
            .'<td><b>Client</b><br>María González<div class="line"></div><span class="muted">Signed </span></td></tr></table></body></html>';
        $signed = (new PrintRenderer())->signedHtml($presented, $this->evidence());

        $this->assertStringContainsString('SIGNED / FIRMADO · Nov 14, 2026</div>', $signed);
        $this->assertStringContainsString('<div class="line">María &lt;González&gt;</div><span class="muted">Signed Nov 14, 2026 2:05 pm CST</span>', $signed);
        $this->assertStringContainsString('Signed Nov 14, 2026 2:05 pm CST · accepted when the client signed', $signed);
        // The same markup outside the signature table (contract text) is never touched.
        $this->assertStringContainsString('<p>Terms keep <div class="line"></div><span class="muted">Signed </span> as text.</p>', $signed);
        $this->assertStringContainsString('Electronic Signature Record', $signed);
    }

    public function test_a_layout_without_the_expected_slots_keeps_the_presented_signature_area(): void
    {
        $presented = '<html><body><div class="chip">Awaiting signature</div><table class="sig"><tr><td>Custom provider block</td><td><div class="line"></div><span class="muted">Signed </span></td></tr></table></body></html>';
        $signed = (new PrintRenderer())->signedHtml($presented, $this->evidence());

        $this->assertStringContainsString('<td>Custom provider block</td><td><div class="line"></div><span class="muted">Signed </span></td>', $signed);
        $this->assertStringNotContainsString('SIGNED / FIRMADO', $signed);
        $this->assertStringContainsString('Awaiting signature', $signed);
        $this->assertStringContainsString('Electronic Signature Record', $signed);
    }
}
