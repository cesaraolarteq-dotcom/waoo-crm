<?php
namespace App\Services\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Validation\ValidationException;
use Mustache\Engine;

final class PrintRenderer
{
    public function validateTerms(string $html): void
    {
        if (strlen($html) > 200000 || preg_match('/<!DOCTYPE|<!ENTITY|<\/?(?:html|head|body)\b|\{\{/i', $html)) {
            throw ValidationException::withMessages(['terms_html' => 'El texto contiene contenido no permitido o supera el límite.']);
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
            foreach ($document->getElementsByTagName('*') as $element) {
                if (! in_array(strtolower($element->tagName), ['html','body','p','h1','h2','h3','h4','ul','ol','li','strong','em','b','i','u','br','span','div','table','thead','tbody','tr','td','th'], true) || $element->attributes->length > 0) {
                    throw ValidationException::withMessages(['terms_html' => 'Usa solo texto y formato básico, sin estilos, enlaces ni recursos externos.']);
                }
            }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    public function html(string $source, string $terms, array $context): string
    {
        $this->validateTerms($terms);
        // Adapt layout-only evidence wording to the access method actually used. Legal clauses are untouched.
        $source = str_replace('through a personal link sent to that email.', '{{signer.access_description}}', $source);
        $source = str_replace('<span class="muted">Signed {{provider.signed_at}}</span>', '<span class="muted">{{#if provider.signed_at}}Signed {{provider.signed_at}}{{/if}}{{#if provider.pending}}Provider acceptance pending{{/if}}</span>', $source);
        $source = preg_replace('/<!--[\s\S]*?-->/', '', $source);
        $source = str_replace('{{payments.balance_amount}}</td></tr>', '{{payments.balance_amount}}</td></tr>{{#if payments.has_credit}}<tr><td>Credit requiring owner resolution</td><td class="r">{{payments.credit_amount}}</td></tr>{{/if}}', $source);
        $stack = [];
        $source = preg_replace_callback('/\{\{(#(?:each|if)\s+([^}]+)|\/(each|if))\}\}/', function ($match) use (&$stack) {
            if (str_starts_with($match[1], '#')) { $stack[] = trim($match[2]); return '{{#'.end($stack).'}}'; }
            if ($stack === []) throw new \LogicException('Invalid trusted print layout.');
            return '{{/'.array_pop($stack).'}}';
        }, $source);
        if ($stack !== []) throw new \LogicException('Unclosed trusted print layout section.');
        $source = str_replace(['{{this}}', '{{terms_html}}'], ['{{.}}', '{{{terms_html}}}'], $source);
        $context['terms_html'] = $terms;
        $html = (new Engine(['escape' => fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]))->render($source, $context);
        if (str_contains($html, '{{')) throw new \LogicException('Unresolved print merge field.');
        return $html;
    }

    public function signedHtml(string $presentedHtml, \App\Models\SignatureEvidence $signature): string
    {
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $certificate = '<section style="page-break-before:always"><h2>Electronic Signature Record</h2><p>The preceding document is the original presentation. This page records the client acceptance of its exact PDF bytes; the exported final PDF is a separate evidence artifact.</p>';
        foreach (['Typed signature' => $signature->signer_name, 'Recorded email' => $signature->signer_email, 'Accepted at (UTC)' => $signature->accepted_at->utc()->format('Y-m-d H:i:s'),
            'Identity method' => 'Scoped personal access link; email delivery/verification not asserted', 'Consent' => $signature->consent_text,
            'Consent version' => $signature->consent_version, 'Presented PDF SHA-256' => $signature->presented_pdf_sha256,
            'Template source SHA-256' => $signature->template_source_sha256, 'Template terms SHA-256' => $signature->template_terms_sha256,
            'Request IP' => $signature->ip, 'Request device' => mb_substr($signature->user_agent ?? '', 0, 120)] as $label => $value) {
            $certificate .= '<p><b>'.$escape($label).':</b> '.$escape($value).'</p>';
        }
        $certificate .= '<p>Only client acceptance is recorded here. Provider countersignature is not asserted. No email or message delivery is asserted.</p></section>';
        return str_replace('</body>', $certificate.'</body>', $presentedHtml);
    }

    public function pdf(string $html): string
    {
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $options->setChroot([base_path('vendor/dompdf/dompdf/lib/fonts')]);
        $pdf = new Dompdf($options); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('letter'); $pdf->render();
        return $pdf->output();
    }
}
