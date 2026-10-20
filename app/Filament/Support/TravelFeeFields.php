<?php

namespace App\Filament\Support;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Variants\Pages\EditServiceVariant;
use App\Services\Routing\TravelFeeQuote;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Hidden, TextInput};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\{Get, Set};
use Illuminate\Validation\ValidationException;

// ROUTE-01 screens: "Calcular" next to the travel fee asks Codex's TravelFeeQuote (owner rules, exact address) and
// fills the fee for the owner to accept. Unknown never becomes $0; a changed or manual fee needs a short reason.
// Shared by the event form and Reserva rápida. Off unless the routes sandbox is on.
final class TravelFeeFields
{
    public static function enabled(): bool
    {
        return FinanceGate::enabled() && (bool) config('waoo.sandbox_routes', false) && class_exists(TravelFeeQuote::class);
    }

    public static function feeField(bool $required, string $help): TextInput
    {
        $field = TextInput::make('travel_fee')->label('Costo de transporte (USD)')->prefix('$')
            ->rule($required ? 'required' : 'nullable')->required($required)->live(onBlur: true)
            ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 40 o 40.50.'])
            ->helperText(fn (Get $get) => self::message($get) ?? $help);
        if (self::enabled()) {
            $field->suffixAction(Action::make('quoteTravel')->label('Calcular')->tooltip('Calcular el costo de transporte con la dirección')->icon('heroicon-o-map')->extraAttributes(['data-places' => 'quote'])
                ->action(function (Get $get, Set $set, $livewire) {
                    self::quote($get, $set);
                    if (method_exists($livewire, 'refreshDeposit')) $livewire->refreshDeposit($get, $set); // Reserva rápida: deposit follows the new total
                }));
        }

        return $field;
    }

    /** Hidden quote state + the reason field. */
    public static function extraFields(): array
    {
        if (! self::enabled()) return [];

        return [
            Hidden::make('travel_fee_quote_id'), Hidden::make('travel_quote_fee_cents'), Hidden::make('travel_quote_status'), Hidden::make('travel_quote_summary'),
            Hidden::make('travel_has_evidence')->dehydrated(false),
            TextInput::make('travel_fee_override_note')->label('¿Por qué este costo?')->maxLength(1000)->columnSpanFull()
                ->placeholder('Ej.: calle privada con caseta; acordado por WhatsApp')
                ->visible(fn (Get $get) => self::needsReason($get) || filled($get('travel_has_evidence')))->required(fn (Get $get) => self::needsReason($get))
                ->helperText('Necesario cuando el costo no es el calculado, no se pudo calcular, o cambias un costo ya aceptado sin calcular de nuevo. Mínimo 10 letras.'),
        ];
    }

    public static function quote(Get $get, Set $set): void
    {
        $address = trim((string) $get('address')); $zip = trim((string) $get('postal_code'));
        if (mb_strlen($address) < 10) {
            Notification::make()->warning()->title('Escribe la dirección completa')->body('Para calcular se usa la dirección exacta de la fiesta (calle, ciudad y ZIP).')->send();

            return;
        }
        try {
            $q = app(TravelFeeQuote::class)->forAddress(Filament::auth()->user(), $address, $zip === '' ? null : $zip);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('No se pudo calcular')->body(collect($e->errors())->flatten()->first())->send();

            return;
        } catch (RecordWriteRejected $e) {
            Notification::make()->danger()->title('No se pudo calcular')->body($e->getMessage())->send();

            return;
        }
        $set('travel_fee_quote_id', $q['quote_id']); $set('travel_quote_fee_cents', $q['fee_cents']); $set('travel_quote_status', $q['status']);
        $set('travel_quote_summary', self::summary($q));
        if ($q['status'] === 'ok' && $q['fee_cents'] !== null) {
            $set('travel_fee', rtrim(rtrim(number_format($q['fee_cents'] / 100, 2, '.', ''), '0'), '.'));
        }
    }

    /**
     * The address or ZIP changed after a quote: that quote no longer describes this address. Mark it stale (never "ok"), so the
     * fee can only be kept with a written reason or replaced by pressing Calcular again; nothing is silently reused.
     */
    public static function invalidate(Get $get, Set $set): void
    {
        if (! self::enabled() || ! filled($get('travel_fee_quote_id')) || $get('travel_quote_status') === 'stale') return;
        $set('travel_quote_status', 'stale');
        $set('travel_quote_summary', 'La dirección cambió: vuelve a calcular el transporte (o explica por qué mantienes este costo).');
    }

    public static function summary(array $q): string
    {
        $b = $q['breakdown'] ?? [];
        $reason = ['zip_required' => 'falta el ZIP', 'zip_mismatch' => 'el ZIP no coincide con la dirección', 'outside_texas' => 'fuera de Texas', 'distance_limit' => 'más de 160 millas ida y vuelta',
            'daily_cap' => 'se llegó al límite de cálculos de hoy', 'provider_unavailable' => 'Google no respondió', 'not_configured' => 'el cálculo con Google no está activado'][$b['reason'] ?? ''] ?? null;

        return match ($q['status']) {
            'ok' => ! empty($b['special_zip']) ? 'Calculado: '.Labels::money($q['fee_cents']).' (precio fijo de ese ZIP).'
                : 'Calculado: '.Labels::money($q['fee_cents']).' · '.round((float) ($b['round_trip_miles'] ?? 0)).' mi ida y vuelta · peajes '.Labels::money((int) ($b['round_trip_tolls_cents'] ?? 0)).'.',
            'outside' => 'Fuera de Texas: escribe el costo a mano con una nota.',
            'review' => 'Requiere revisión ('.($reason ?? 'revisar dirección').'): escribe el costo a mano con una nota.',
            default => 'Pendiente: no se pudo calcular ahora'.($reason ? ' ('.$reason.')' : '').'. Escribe el costo a mano con una nota o intenta más tarde.',
        };
    }

    public static function message(Get $get): ?string
    {
        return filled($get('travel_quote_summary')) ? (string) $get('travel_quote_summary') : null;
    }

    public static function needsReason(Get $get): bool
    {
        if (! filled($get('travel_fee_quote_id')) || ! filled($get('travel_fee'))) return false;
        $fee = preg_match('/^\d{1,7}(\.\d{1,2})?$/', (string) $get('travel_fee')) ? EditServiceVariant::toCents((string) $get('travel_fee')) : null;

        return $get('travel_quote_status') !== 'ok' || (int) $get('travel_quote_fee_cents') !== $fee;
    }

    /** Service input keys (only when the routes sandbox is on, so the manual flow stays exactly as before). */
    public static function input(array $s): array
    {
        if (! self::enabled()) return [];

        return array_filter([
            'travel_fee_quote_id' => filled($s['travel_fee_quote_id'] ?? null) ? (int) $s['travel_fee_quote_id'] : null,
            'travel_fee_override_note' => filled($s['travel_fee_override_note'] ?? null) ? trim($s['travel_fee_override_note']) : null,
        ], fn ($v) => $v !== null);
    }
}
