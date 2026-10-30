<?php
namespace App\Services\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Validation\ValidationException;
use Mustache\Engine;

final class PrintRenderer
{
    private const TERMS_FIELD = '/(?<!\{)\{\{\s*(client\.first_name|client\.last_name|payments\.balance_amount)\s*\}\}(?!\})/';

    public function validateTerms(string $html): void
    {
        $withoutFields = preg_replace(self::TERMS_FIELD, '', $html);
        if (str_contains($withoutFields, '{{') || str_contains($withoutFields, '}}')) {
            throw ValidationException::withMessages(['terms_html' => 'Solo se permiten client.first_name, client.last_name y payments.balance_amount.']);
        }
        if (strlen($html) > 200000 || preg_match('/<!DOCTYPE|<!ENTITY|<\/?(?:html|head|body)\b/i', $html)) {
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
        if (!empty($context['signed'])) {
            // Final-presentation styling only; immutable template and unsigned artifacts stay unchanged.
            $source = str_replace('<div class="line">{{client.typed_signature}}</div>', '<div class="line p-typed-signature">{{client.typed_signature}}</div>', $source);
            $source = str_replace('</style>', '.sig .p-typed-signature { font-family: "DejaVu Serif", serif; font-style: italic; font-size: 19pt; height: auto; min-height: 26pt; word-wrap: break-word; }</style>', $source);
            // The evidence block must quote the exact recorded consent, including its language/version.
            $source = str_replace('Consent: "I have read and accept the contract. My electronic signature is as valid as a handwritten one."', 'Consent: "{{signer.consent}}"', $source);
        }
        if(!empty($context['billing_company']))$source=str_replace('<body>', '<body>{{#billing_company}}<section><p><strong>Facturar a / Bill to: {{name}}</strong> · Atención / Attention: {{person}}<br>{{email}}<br>{{address}}</p></section>{{/billing_company}}', $source);
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
        // Substitute approved fields once; client values are data, never template source.
        $context['terms_html'] = preg_replace_callback(self::TERMS_FIELD, function ($match) use ($context) {
            $value = data_get($context, $match[1]);
            if (! is_scalar($value)) throw new \LogicException('Missing approved terms field.');
            return str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }, $terms);
        $html = (new Engine(['escape' => fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]))->render($source, $context);
        if (str_contains($html, '{{')) throw new \LogicException('Unresolved print merge field.');
        return $html;
    }

    public function signedHtml(string $presentedHtml, \App\Models\SignatureEvidence $signature, bool $signedPresentation = false): string
    {
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $acceptance = $signedPresentation
            ? "Provider acceptance (Waoo Party LLC) recorded at the client's signature by owner decision of 2026-10-28."
            : 'Only client acceptance is recorded here. Provider countersignature is not asserted.';
        $certificate .= '<p>'.$escape($acceptance).' No email or message delivery is asserted.</p></section>';
