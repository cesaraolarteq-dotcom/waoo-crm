<?php

namespace App\Filament\Support;

use App\Filament\Resources\Events\EventResource;
use App\Models\{Contract, Event, EventChange, ImportedProjectLink, ImportedProjectRevision};
use App\Services\Finance\FinanceSummary;
use App\Services\Team\TeamSlots;

/**
 * Owner 2026-11-02 audit: one header for every tab of a party (Resumen · Dinero · Contrato · Equipo): the name, when and
 * where, and a few colored states that say what is still missing. The pages no longer repeat their own chips.
 */
final class PartyHeader
{
    public static function subheading(Event $e): string
    {
        $end = $e->ends_at->setTimezone(Labels::TZ)->locale('es')->translatedFormat('g:i a');

        $client = $e->customer?->name;
        // The client goes here only when the party's name does not already say it.
        $client = filled($client) && ! str_contains(mb_strtolower((string) $e->title), mb_strtolower($client)) ? $client : null;

        return implode(' · ', array_filter([$client, Labels::when($e->starts_at).' – '.$end, $e->postal_code ? 'ZIP '.$e->postal_code : null]));
    }

    public static function breadcrumbs(): array
    {
        return [EventResource::getUrl('index') => 'Fiestas'];
    }

    /** @return list<array{label:string,tone:string,title?:string}> tone: ok | warn | bad | muted */
    public static function chips(Event $e): array
    {
        $chips = [['label' => Labels::label(Labels::EVENT_STATUS, $e->status), 'tone' => ['capacity_reserved' => 'ok', 'cancelled' => 'bad'][$e->status] ?? 'muted']];
        if ($e->status === 'cancelled') return $chips;

        if (($e->getAttribute('responsible_system')) === 'honeybook') {
            $chips[] = ['label' => 'HoneyBook cobra y avisa', 'tone' => 'muted', 'title' => 'Solo un sistema cobra y manda avisos por fiesta'];
        } elseif (FinanceGate::enabled()) {
            $f = app(FinanceSummary::class)->forEvent($e);
            $rev = $f['financial_revision'];
            $chips[] = match (true) {
                ! $rev => ['label' => 'Montos sin fijar', 'tone' => 'warn'],
                $f['balance_cents'] === 0 => ['label' => 'Pagado', 'tone' => 'ok'],
                $f['net_received_cents'] <= 0 => ['label' => 'Sin pagos · '.Labels::money($rev->total_cents), 'tone' => 'warn'],
                default => ['label' => 'Saldo '.Labels::money($f['balance_cents']).($rev->balance_due_at ? ' · vence '.self::day($rev->balance_due_at) : ''), 'tone' => 'warn'],
            };
        }

        $contract = self::contract($e);
        $chips[] = ['label' => 'Contrato: '.$contract, 'tone' => str_starts_with($contract, 'Firmado') ? 'ok' : 'warn'];

        if ($e->status === 'capacity_reserved') {
            $need = TeamSlots::for($e); $cur = TeamSlots::current($e);
            $gaps = count(array_filter($need['people'], fn ($s) => ! ($cur['people'][$s['key']] ?? null)))
                + count(array_filter($need['costumes'], fn ($s) => ! (($cur['costumes'][$s['key']]['wearer_id'] ?? null) && ($cur['costumes'][$s['key']]['unit_id'] ?? null))));
            if ($need['people'] !== [] || $need['costumes'] !== []) {
                $chips[] = $gaps === 0 ? ['label' => 'Equipo completo', 'tone' => 'ok'] : ['label' => 'Equipo: '.($gaps === 1 ? 'falta 1' : 'faltan '.$gaps), 'tone' => 'warn'];
            }
        }

        if ($change = EventChange::pendingSignature($e->id)) {
            $chips[] = ['label' => 'Cambio de fecha por firmar', 'tone' => 'warn', 'title' => 'Antes: '.Labels::when($change->old_starts_at)];
        }

        return $chips;
    }

    public static function contract(Event $e): string
    {
        $link = ImportedProjectLink::where('event_id', $e->id)->first();
        if ($link) {
            $signed = (bool) (ImportedProjectRevision::find($link->current_revision_id)?->normalized['contract_signed'] ?? false);

            return $signed ? 'Firmado en HoneyBook' : 'Sin firmar en HoneyBook';
        }

        return match (Contract::where('event_id', $e->id)->orderByDesc('revision')->value('state')) {
            'signed' => 'Firmado', 'issued' => 'Por firmar', 'draft' => 'Preparado, sin enviar', default => 'Sin preparar',
        };
    }

    private static function day($at): string
    {
        $d = $at->setTimezone(Labels::TZ)->locale('es');

        return $d->translatedFormat($d->year === now(Labels::TZ)->year ? 'j M' : 'j M Y');
    }
}
