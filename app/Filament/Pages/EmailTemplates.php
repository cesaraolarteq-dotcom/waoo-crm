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
        return 'Borradores para revisar. Todavía no se envía ningún correo. Los datos de la vista previa son de ejemplo.';
    }

    public function templates(): array
    {
        return array_map(fn ($key) => (object) [
            'key' => $key, 'when' => MailTemplatesDraft::WHEN[$key],
            'subject_es' => MailTemplatesDraft::render($key, 'es', ['first_name' => 'Ana', 'event_date' => 'sábado 21 de noviembre', 'due' => 'viernes 20 de noviembre'])['subject'],
            'subject_en' => MailTemplatesDraft::render($key, 'en', ['first_name' => 'Ana', 'event_date' => 'Sat, Nov 21', 'due' => 'Fri, Nov 20'])['subject'],
        ], MailTemplatesDraft::KEYS);
    }

    public function previewHtml(string $key, string $lang): string
    {
        abort_unless(in_array($key, MailTemplatesDraft::KEYS, true), 404);

        return MailTemplatesDraft::preview($key, $lang === 'en' ? 'en' : 'es');
    }
}
