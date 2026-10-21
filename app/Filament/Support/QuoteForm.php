<?php

namespace App\Filament\Support;

use App\Filament\Resources\Variants\Pages\EditServiceVariant;
use App\Services\Quotes\CreateQuote;
use Filament\Forms\Components\{DatePicker, Repeater, Select, Textarea, TextInput, TimePicker};
use Filament\Schemas\Components\Grid;

// QUOTE-01 A form pieces shared by "Crear cotización" (event) and "Nueva revisión" (quote): validity, language,
// private note and the COMPLETE explicit discount list. Totals, approvals and prices are never sent from the browser.
final class QuoteForm
{
    public const STATUS = ['draft' => 'Borrador', 'sent' => 'Enviada', 'accepted' => 'Aceptada', 'expired' => 'Vencida', 'superseded' => 'Sustituida', 'rejected' => 'Rechazada'];

    public static function enabled(): bool
    {
        return FinanceGate::enabled() && (bool) config('waoo.sandbox_quotes', false) && class_exists(CreateQuote::class);
    }

    public static function schema(): array
    {
        return [
            Grid::make(3)->schema([
                DatePicker::make('valid_date')->label('Válida hasta (opcional)')->native()->helperText('Vacío = 7 días. Debe ser antes de la fiesta.'),
                TimePicker::make('valid_time')->label('Hora (Houston)')->native()->seconds(false),
                Select::make('locale')->label('Idioma del cliente')->options(['es' => 'Español', 'en' => 'Inglés'])->native(false),
            ]),
            Repeater::make('discounts')->label('Descuentos (opcional, siempre visibles para el cliente)')->defaultItems(0)->maxItems(5)->columns(2)
                ->addActionLabel('Agregar descuento')
                ->schema([
                    TextInput::make('label')->label('Cómo lo verá el cliente')->required()->minLength(3)->maxLength(255)->placeholder('Ej.: Descuento por reservar hoy'),
                    TextInput::make('amount')->label('Monto (USD)')->prefix('$')->required()
                        ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Solo el número, por ejemplo 50.']),
                    Textarea::make('reason')->label('Por qué (solo lo ves tú)')->required()->minLength(10)->maxLength(1000)->rows(2)->columnSpanFull(),
                    DatePicker::make('expires_date')->label('El descuento vence (opcional)')->native(),
                ])
                ->helperText('En cada revisión se manda la lista completa: si quitas un descuento aquí, la nueva versión ya no lo tiene.'),
            Textarea::make('owner_note')->label('Nota privada')->maxLength(2000)->rows(2),
        ];
    }

    /** Form → service input (validity with the Houston offset; discounts in cents; nothing computed here). */
    public static function input(array $d): array
    {
        $out = ['discounts' => collect($d['discounts'] ?? [])->map(fn ($x) => array_filter([
            'label' => trim((string) $x['label']), 'amount_cents' => EditServiceVariant::toCents((string) $x['amount']), 'reason' => trim((string) $x['reason']),
            'expires_at' => filled($x['expires_date'] ?? null) ? ChicagoTime::toIso($x['expires_date'], '23:59', null, 'discounts') : null,
        ], fn ($v) => $v !== null))->values()->all()];
        if (filled($d['valid_date'] ?? null)) $out['valid_until'] = ChicagoTime::toIso($d['valid_date'], $d['valid_time'] ?: '23:59', null, 'valid_date');
        if (filled($d['locale'] ?? null)) $out['locale'] = $d['locale'];
        if (array_key_exists('owner_note', $d)) $out['owner_note'] = filled($d['owner_note']) ? trim($d['owner_note']) : null;

        return $out;
    }

    /** Stored discounts → form rows (so a revision starts from what the owner sees, and removing one is explicit). */
    public static function formDiscounts(array $snapshot): array
    {
        return collect($snapshot)->map(fn ($x) => ['label' => $x['label'], 'amount' => rtrim(rtrim(number_format($x['amount_cents'] / 100, 2, '.', ''), '0'), '.'),
            'reason' => $x['reason'] ?? '', 'expires_date' => isset($x['expires_at']) ? \Carbon\CarbonImmutable::parse($x['expires_at'])->setTimezone(ChicagoTime::TZ)->format('Y-m-d') : null])->values()->all();
    }
}
