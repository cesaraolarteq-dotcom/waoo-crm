<?php

namespace App\Filament\Pages;

use App\Support\MailTemplatesDraft;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

// MAIL-01 settings (read-only): the client emails, when each goes out, and an EN/ES preview with sample data.
// Drafts only — sending is Codex's MAIL-01 backend and stays off until the owner activates it.
class EmailTemplates extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;
    protected static string|\UnitEnum|null $navigationGroup = 'Vender';
    protected static ?string $navigationLabel = 'Correos';
    protected static ?int $navigationSort = 40;
    protected static ?string $slug = 'correos';
    protected static ?string $title = 'Correos a las clientas';
    protected string $view = 'filament.pages.email-templates';

    public function getSubheading(): ?string
    {
        return 'Textos aprobados por César. Los envíos requieren activación; los datos de la vista previa son de ejemplo.';
    }

    public function templates(): array
    {
        return array_map(fn ($key) => (object) [
            'key' => $key, 'when' => MailTemplatesDraft::WHEN[$key],
            'subject_es' => MailTemplatesDraft::render($key, 'es', ['first_name' => 'Ana', 'event_date' => 'sábado 21 de noviembre', 'subject_date' => 'sábado 21 de noviembre', 'due' => 'viernes 20 de noviembre'])['subject'],
            'subject_en' => MailTemplatesDraft::render($key, 'en', ['first_name' => 'Ana', 'event_date' => 'Sat, Nov 21', 'subject_date' => 'Sat, Nov 21', 'due' => 'Fri, Nov 20'])['subject'],
        ], MailTemplatesDraft::KEYS);
    }

    /**
     * What is still missing before any real email can go out (Codex's ReadOutboundMail::settings, read-only,
     * readable while mail is off). No credentials, no editing here: activation is the owner's explicit step.
     */
    public function readiness(): ?array
    {
        if (! class_exists(\App\Services\Mail\ReadOutboundMail::class)) {
            return null;
        }
        try {
            $s = app(\App\Services\Mail\ReadOutboundMail::class)->settings(\Filament\Facades\Filament::auth()->user());
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return null;
        }
        $approved = collect($s['templates'])->where('approved', true);
        $missing = array_values(array_filter([
            $approved->count() < count($s['templates']) ? 'Aprobar los textos ('.$approved->count().' de '.count($s['templates']).' aprobados, contando español e inglés).' : null,
            $s['transport_configured'] ? null : 'Conectar un proveedor de correo y configurar el remitente (dominio, DNS).',
            $s['allowlist'] === [] ? 'Poner tu correo en la lista de prueba para recibir los primeros envíos.' : null,
            $s['enabled'] ? null : 'Encender los correos de prueba (lo hace Claude cuando tú lo autorices).',
        ]));

        return [...$s, 'approved_count' => $approved->count(), 'missing' => $missing,
            'rows' => collect($s['templates'])->groupBy('key')->map(fn ($v, $k) => (object) ['label' => \App\Filament\Support\MailUi::NAMES[$k] ?? $k,
                'es' => (bool) $v->firstWhere('locale', 'es')['approved'], 'en' => (bool) $v->firstWhere('locale', 'en')['approved']])->values()->all()];
    }

    public function previewHtml(string $key, string $lang): string
    {
        abort_unless(in_array($key, MailTemplatesDraft::KEYS, true), 404);

        return MailTemplatesDraft::preview($key, $lang === 'en' ? 'en' : 'es');
    }
}
