<?php

namespace App\Filament\Pages;

use App\Filament\Support\Labels;
use App\Models\{Resource, StaffPayLine, StaffPayRate};
use App\Services\Audit;
use App\Services\Team\StaffPaySuggestion;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

// Owner 2026-11-03: suggested pay per person for the week, with why; the owner can change any amount and mark it paid.
class StaffPay extends Page
{
    protected static ?string $slug = 'pago-del-staff';
    protected static ?string $title = 'Pago del staff';
    protected static bool $shouldRegisterNavigation = false; // reached through the Equipo tabs
    protected string $view = 'filament.pages.staff-pay';

    public string $monday = '';
    /** role => ['per' => hour|party, 'amount' => '15.00'] */
    public array $rates = [];
    /** "eventId-resourceId" => ['amount' => '45.00', 'note' => ''] */
    public array $edits = [];
    public bool $editingRates = false;

    public function mount(): void
    {
        // Pay is about work already done: open on last week until Sunday is over, then this one.
        $now = CarbonImmutable::now('America/Chicago');
        $this->monday = $now->startOfWeek(CarbonImmutable::MONDAY)->subWeek()->toDateString();
        $this->loadRates();
        $this->editingRates = StaffPayRate::count() === 0;
    }

    public function shiftWeek(int $weeks): void
    {
        $this->monday = CarbonImmutable::parse($this->monday)->addWeeks($weeks)->toDateString();
        $this->edits = [];
    }

    /** Roles the team really has, plus driving. */
    public function roles(): array
    {
        $skills = Resource::where('kind', 'staff')->where('status', 'active')->pluck('skills')->flatten()->filter()->unique()
            ->reject(fn ($s) => $s === 'driver')->values()->all();
        $out = [];
        foreach ($skills as $s) $out[$s] = Labels::SKILL[$s] ?? $s;
        asort($out);

        return $out + [StaffPaySuggestion::DRIVING => 'Manejar (además del puesto)'];
    }

    private function loadRates(): void
    {
        $saved = StaffPayRate::all()->keyBy('role');
        foreach ($this->roles() as $role => $label) {
            $r = $saved[$role] ?? null;
            $this->rates[$role] = ['per' => $r?->per ?? ($role === StaffPaySuggestion::DRIVING ? 'party' : 'hour'), 'amount' => $r ? number_format($r->amount_cents / 100, 2, '.', '') : ''];
        }
    }

    public function saveRates(): void
    {
        $user = Filament::auth()->user();
        DB::transaction(function () use ($user) {
            foreach ($this->rates as $role => $r) {
                if (! array_key_exists($role, $this->roles())) continue;
                $amount = trim((string) ($r['amount'] ?? ''));
                if ($amount === '') { StaffPayRate::where('role', $role)->delete(); continue; }
                if (! preg_match('/^\d{1,5}(\.\d{1,2})?$/', $amount)) { Notification::make()->danger()->title('Revisa las tarifas')->body('Escribe solo el número, por ejemplo 15 o 15.50.')->send(); return; }
                $row = StaffPayRate::updateOrCreate(['role' => $role], ['per' => in_array($r['per'] ?? '', ['hour', 'party'], true) ? $r['per'] : 'hour',
                    'amount_cents' => (int) round(((float) $amount) * 100), 'updated_by' => $user->id]);
                Audit::recordFor($user, 'staff_pay.rate_saved', $row, [], $row->only(['role', 'per', 'amount_cents']));
            }
            $this->editingRates = false;
            Notification::make()->success()->title('Tarifas guardadas')->body('Las sugerencias ya usan estas tarifas.')->send();
        });
    }

    public function saveLine(int $eventId, int $resourceId, ?int $suggested): void
    {
        $key = $eventId.'-'.$resourceId;
        $amount = trim((string) ($this->edits[$key]['amount'] ?? ''));
        if (! preg_match('/^\d{1,5}(\.\d{1,2})?$/', $amount)) { Notification::make()->danger()->title('Escribe el monto')->body('Solo el número, por ejemplo 45.')->send(); return; }
        $user = Filament::auth()->user();
        $line = StaffPayLine::updateOrCreate(['event_id' => $eventId, 'resource_id' => $resourceId],
            ['amount_cents' => (int) round(((float) $amount) * 100), 'suggested_cents' => $suggested, 'note' => filled($n = trim((string) ($this->edits[$key]['note'] ?? ''))) ? mb_substr($n, 0, 300) : null, 'updated_by' => $user->id]);
        Audit::recordFor($user, 'staff_pay.line_saved', $line, [], $line->only(['event_id', 'resource_id', 'amount_cents', 'suggested_cents']));
        unset($this->edits[$key]);
        Notification::make()->success()->title('Monto guardado')->send();
    }

    public function edit(int $eventId, int $resourceId, int $cents, ?string $note): void
    {
        $this->edits[$eventId.'-'.$resourceId] = ['amount' => number_format($cents / 100, 2, '.', ''), 'note' => (string) $note];
    }

    /** Marks every party of this person in the week as paid, keeping the amount shown (suggested or changed). */
    public function markPaid(int $resourceId): void
    {
        $person = collect($this->board())->firstWhere('resource_id', $resourceId);
        if (! $person || $person['missing_rate']) { Notification::make()->warning()->title('Falta una tarifa')->body('Pon la tarifa o escribe el monto antes de marcarlo pagado.')->send(); return; }
        $user = Filament::auth()->user();
        DB::transaction(function () use ($person, $resourceId, $user) {
            foreach ($person['lines'] as $l) {
                $line = StaffPayLine::firstOrNew(['event_id' => $l['event_id'], 'resource_id' => $resourceId]);
                $line->fill(['amount_cents' => $l['amount_cents'], 'suggested_cents' => $line->exists ? $line->suggested_cents : $l['suggested_cents'], 'paid_at' => now(), 'updated_by' => $user->id])->save();
                Audit::recordFor($user, 'staff_pay.paid', $line, [], $line->only(['event_id', 'resource_id', 'amount_cents']));
            }
        });
        Notification::make()->success()->title('Marcado como pagado')->body($person['name'].' · '.Labels::money($person['total_cents']))->send();
    }

    public function board(): array
    {
        return app(StaffPaySuggestion::class)->week(CarbonImmutable::parse($this->monday, 'America/Chicago'));
    }

    public function range(): string
    {
        $m = CarbonImmutable::parse($this->monday)->locale('es');

        return $m->translatedFormat('j M').' – '.$m->addDays(6)->translatedFormat('j M');
    }
}
