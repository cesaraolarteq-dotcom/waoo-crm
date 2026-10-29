<?php

namespace App\Services\Reports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, Event, EventCostSnapshot, FinancialRevision, HistoricalEvent, HistoricalEventRevision, ImportedLedgerLine, ImportedProjectLink, ImportedProjectRevision, Lead, LedgerEntry, User};
use App\Services\History\{HistoricalIntegrity, ReadHoneyBookEventFacts};
use App\Services\Records\RecordActor;
use Carbon\CarbonImmutable;

/**
 * Read-only rows for Panorama: HoneyBook history (past parties and their payments), HoneyBook parties already in the
 * CRM (current imported revision), and parties booked in the CRM (finance revision, ledger, cost snapshot).
 * A party that is both history and imported counts once (the imported copy wins). Writes nothing.
 */
final class ReadPanorama
{
    private const CONFIRMED = ['capacity_reserved', 'completed'];

    public function execute(User $actor, string $from, string $to, ?string $today = null): array
    {
        RecordActor::authorize($actor);
        $today ??= now('America/Chicago')->toDateString();
        [$parties, $payments, $skipped] = $this->rows();
        $leads = Lead::query()->pluck('created_at')->map(fn ($d) => CarbonImmutable::parse($d)->setTimezone('America/Chicago')->toDateString())->all();

        return [...app(Panorama::class)->build($parties, $payments, $leads, $from, $to, $today), 'skipped_records' => $skipped];
    }

    /** @return array{0: array, 1: array, 2: int} */
    public function rows(): array
    {
        $parties = $payments = [];
        $skipped = 0;
        $names = Customer::query()->pluck('name', 'id')->all();
        $name = fn (?int $id) => ($id && ($names[$id] ?? null)) ? $names[$id] : 'Cliente sin nombre';
        $links = ImportedProjectLink::query()->get()->keyBy('event_id');
        $importedKeys = $links->pluck('project_key')->flip()->all();

        if (config('waoo.sandbox_history')) {
            foreach (HistoricalEvent::query()->orderBy('id')->get() as $event) {
                if (isset($importedKeys[$event->project_key])) continue;
                try {
                    $p = HistoricalIntegrity::revision($event, HistoricalEventRevision::findOrFail($event->current_revision_id));
                } catch (RecordWriteRejected|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $skipped++;
                    continue;
                }
                $f = $p['facts'];
                $s = $p['source'];
                if (($f['record_kind'] ?? '') !== 'event' || $f['status'] !== 'completed' || ! $f['source_date']) continue;
                $parties[] = ['key' => 'h'.$event->id, 'date' => $f['source_date'], 'total_cents' => ($f['currency'] ?? null) === 'USD' ? $f['booked_total_cents'] : null,
                    'zip' => $s['postal_code'] ?? null, 'city' => $s['city'] ?? null, 'source' => trim($s['lead_source_text'] ?? '') ?: ($s['lead_source'] ?? null),
                    'booked_on' => $this->day($s['created_at'] ?? null), 'customer_id' => $event->customer_id, 'name' => $name($event->customer_id),
                    'balance_cents' => null, 'balance_due' => null, 'profit' => null];
                $list = [];
                foreach ($f['payments'] ?? [] as $pay) {
                    $cents = preg_match('/^(0|[1-9]\d{0,8})\.\d{2}$/D', $pay['amount_text']) ? (int) str_replace('.', '', $pay['amount_text']) : null;
                    if ($cents && $this->day($pay['date'])) $list[] = ['date' => $this->day($pay['date']), 'amount_cents' => $cents];
                }
                array_push($payments, ...$this->markDeposit($list));
            }
        }

        $events = Event::query()->orderBy('id')->get();
        $finances = FinancialRevision::query()->whereIn('event_id', $events->modelKeys())->orderByDesc('revision')->get()->unique('event_id')->keyBy('event_id');
        $paid = LedgerEntry::query()->where('environment', 'test')->where('currency', 'USD')->whereIn('event_id', $events->modelKeys())
            ->selectRaw("event_id, SUM(CASE WHEN kind = 'receipt' THEN amount_cents WHEN kind = 'refund' THEN -amount_cents ELSE 0 END) AS net")
            ->groupBy('event_id')->pluck('net', 'event_id');
        foreach ($events as $event) {
            $date = $event->starts_at?->setTimezone('America/Chicago')->toDateString();
            if (! $date) continue;
            $link = $links->get($event->id);
            if ($link) {
                $rev = ImportedProjectRevision::find($link->current_revision_id);
                if (! $rev) { $skipped++; continue; }
                $n = $rev->normalized;
                $facts = ReadHoneyBookEventFacts::forEvent($event, false);
                $parties[] = ['key' => 'i'.$event->id, 'date' => $date, 'total_cents' => $n['total_cents'] ?? null, 'zip' => $event->postal_code, 'city' => null,
                    'source' => $facts['stale'] ? null : ($facts['fields']['lead_source'] ?? null), 'booked_on' => null,
                    'customer_id' => $event->customer_id, 'name' => $name($event->customer_id),
                    'balance_cents' => $n['balance_cents'] ?? null, 'balance_due' => $this->day($n['balance_due_date'] ?? null), 'profit' => null];
                $list = [];
                foreach (ImportedLedgerLine::query()->where('imported_project_revision_id', $rev->id)->get() as $line) {
                    if ($line->amount_cents > 0 && $line->received_date) $list[] = ['date' => $line->received_date->toDateString(), 'amount_cents' => $line->amount_cents];
                }
                array_push($payments, ...$this->markDeposit($list));
                continue;
            }
            if (! in_array($event->status, self::CONFIRMED, true)) continue;
            $finance = $finances->get($event->id);
            $total = $finance && $finance->currency === 'USD' ? $finance->total_cents : null;
            $parties[] = ['key' => 'e'.$event->id, 'date' => $date, 'total_cents' => $total, 'zip' => $event->postal_code, 'city' => null, 'source' => null,
                'booked_on' => $event->created_at?->setTimezone('America/Chicago')->toDateString(), 'customer_id' => $event->customer_id, 'name' => $name($event->customer_id),
                'balance_cents' => $total === null ? null : max($total - (int) ($paid[$event->id] ?? 0), 0), 'balance_due' => null,
                'profit' => $finance ? $this->profit($event, $finance) : null];
        }

        $receipts = LedgerEntry::query()->where('environment', 'test')->where('currency', 'USD')->where('kind', 'receipt')->whereNotNull('received_at')->get()->groupBy('event_id');
        foreach ($receipts as $entries) {
            array_push($payments, ...$this->markDeposit($entries->map(fn ($e) => ['date' => CarbonImmutable::parse($e->received_at)->setTimezone('America/Chicago')->toDateString(), 'amount_cents' => $e->amount_cents])->all()));
        }

        return [$parties, $payments, $skipped];
    }

    /** Package sale against the newest complete cost record (real first, else estimate) for the current agreement. */
    private function profit(Event $event, FinancialRevision $finance): ?array
    {
        foreach (['actual', 'estimate'] as $kind) {
            $s = EventCostSnapshot::query()->where('event_id', $event->id)->where('kind', $kind)->orderByDesc('revision')->first();
            if (! $s || ! $s->complete || $s->financial_revision_id !== $finance->id || $s->event_version !== $event->version || $s->currency !== 'USD') continue;
            $lines = collect($s->lines_snapshot)->reject(fn ($l) => $l['category'] === 'card_processing');
            $cost = (int) $lines->sum('amount_cents') + ($lines->contains('category', 'materials') ? 0 : Panorama::MATERIALS_CENTS);

            return ['revenue_cents' => (int) $finance->subtotal_cents, 'cost_cents' => $cost];
        }

        return null;
    }

    /** The first payment of a party is its booking deposit (what "Pulso del dinero" measures). */
    private function markDeposit(array $list): array
    {
        usort($list, fn ($a, $b) => $a['date'] <=> $b['date']);

        return array_map(fn ($p, $i) => [...$p, 'deposit' => $i === 0], $list, array_keys($list));
    }

    private function day(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) return null;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $d && $d->format('Y-m-d') === substr($value, 0, 10) ? substr($value, 0, 10) : null;
    }
}
