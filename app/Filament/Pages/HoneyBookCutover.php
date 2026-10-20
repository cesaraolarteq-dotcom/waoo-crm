<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\{CutoverUi, Labels};
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// C3 A (docs/CUTOVER-EVIDENCE-API.md): the whole HoneyBook group, its evidence per party and the frozen preparation.
// Evidence only: transfer is never available here; HoneyBook keeps charging. 4-hour rule from the oldest owner review.
class HoneyBookCutover extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;
    protected static string|\UnitEnum|null $navigationGroup = 'Operación';
    protected static ?string $navigationLabel = 'Cambio final';
    protected static ?int $navigationSort = 95;
    protected static ?string $slug = 'cambio-final';
    protected static ?string $title = 'Cambio final: HoneyBook → CRM';
    protected string $view = 'filament.pages.honeybook-cutover';

    #[Locked]
    public ?string $displayedCohort = null;

    #[Locked]
    public string $prepareKey = '';

    public bool $scopeReviewed = false;

    /** The group hash the owner had on screen when ticking the scope box; the attestation never carries to another group. */
    #[Locked]
    public ?string $reviewedCohort = null;

    /** C3 B1 (docs/CUTOVER-OPENING-API.md): reconciliation + group hash on screen when "Preparar acuerdos" was shown. */
    #[Locked]
    public array $openingPin = [];

    #[Locked]
    public string $openingKey = '';

    public const OPENING_MISSING = [
        'opening_expired' => 'Venció la evidencia del grupo (4 horas): lo de abajo es una foto de ese momento.',
        'opening_source_changed' => 'Cambió algo de HoneyBook desde que se preparó.',
        'opening_capacity_evidence_changed' => 'Cambió el staff, los trajes o la evidencia de alguna fiesta.',
    ];

    public static function canAccess(): bool
    {
        return parent::canAccess() && CutoverUi::enabled();
    }

    public function getSubheading(): ?string
    {
        return 'Solo evidencia: aquí no se pasa nada al CRM ni se apaga HoneyBook. HoneyBook sigue cobrando y avisando.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->prepareKey = (string) Str::uuid();
        $this->openingKey = (string) Str::uuid();
    }

    public static function bridgeEnabled(): bool
    {
        return config('waoo.sandbox_cutover_bridge') === true && class_exists(\App\Services\Imports\PrepareCutoverOpeningAgreements::class);
    }

    /** Members page on screen (25 per page). Paging only shows parties; preparation always covers the whole group. */
    #[Locked]
    public int $memberPage = 1;

    private ?array $snapshot = null;

    /**
     * ONE bounded read per render (docs/CUTOVER-MEMBERS-API.md): readiness, totals and this page's rows come from the same
     * consistent snapshot. After the first read the displayed group hash is sent; if the group changed the server refuses (409),
     * the scope tick and retry key are cleared and page 1 is reloaded without the old hash.
     */
    public function readiness(): ?array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }
        $read = fn (array $f) => app(\App\Services\Imports\ReadHoneyBookCutoverMembers::class)->execute(Filament::auth()->user(), $f);
        $filters = ['page' => max(1, $this->memberPage), 'per_page' => 25];
        try {
            $r = $read($this->displayedCohort !== null ? [...$filters, 'expected_cohort_sha256' => $this->displayedCohort] : $filters);
        } catch (\App\Exceptions\CutoverCohortChanged) {
            $this->forgetScopeReview();
            $this->displayedCohort = null;
            $this->memberPage = 1;
            Notification::make()->warning()->title('El grupo de fiestas cambió')->body('Volvimos a la primera página. Revisa el grupo otra vez antes de preparar.')->send();
            try {
                $r = $read(['page' => 1, 'per_page' => 25]);
            } catch (\Throwable) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        if ($this->displayedCohort !== null && $this->displayedCohort !== $r['cohort_sha256']) {
            $this->forgetScopeReview(); // the group changed under the owner: a new explicit review is required
        }
        $this->displayedCohort = $r['cohort_sha256'];
        $this->memberPage = $r['page']['number'];
        $pin = ($r['reconciliation']['current'] ?? false) ? ['reconciliation_id' => $r['reconciliation']['id'], 'cohort' => $r['reconciliation']['cohort_sha256']] : [];
        if ($pin !== $this->openingPin) {
            $this->openingPin = $pin;
            $this->openingKey = (string) Str::uuid(); // another reconciliation on screen: never reuse the old retry key
        }

        return $this->snapshot = $r;
    }

    public function goMemberPage(int $page): void
    {
        $this->memberPage = max(1, $page);
        $this->snapshot = null;
    }

    public function updatedScopeReviewed(bool $value): void
    {
        $this->reviewedCohort = $value ? $this->displayedCohort : null;
    }

    private function forgetScopeReview(): void
    {
        $this->scopeReviewed = false;
        $this->reviewedCohort = null;
        $this->prepareKey = (string) Str::uuid();
    }

    /** This page's parties from the same snapshot as readiness (no per-row reads, no contact data). */
    public function members(): array
    {
        $r = $this->readiness();

        return collect($r['rows'] ?? [])->map(fn ($row) => ['event_id' => $row['event_id'], 'title' => $row['title'],
            'when' => $row['starts_at'] ? Labels::when(\Carbon\CarbonImmutable::parse($row['starts_at'])) : '',
            'missing' => $row['missing'], 'review' => $row['review']])->all();
    }

    public static function when(?string $iso): string
    {
        return $iso ? rtrim(Labels::when(\Carbon\CarbonImmutable::parse($iso)), '.') : '—';
    }

    public function prepare(): void
    {
        if (! $this->scopeReviewed || $this->reviewedCohort === null || $this->reviewedCohort !== $this->displayedCohort) {
            $this->forgetScopeReview();
            Notification::make()->warning()->title('Falta confirmar el alcance')->body('Marca que el archivo de HoneyBook tiene todas las fiestas que siguen activas.')->send();

            return;
        }
        try {
            app(\App\Services\Imports\PrepareHoneyBookCutoverReconciliation::class)->execute(Filament::auth()->user(),
                ['expected_cohort_sha256' => $this->reviewedCohort, 'source_scope_reviewed' => true], $this->prepareKey);
        } catch (ValidationException $e) {
            $this->forgetScopeReview();
            Notification::make()->danger()->title('No se preparó')->body(collect($e->errors())->flatten()->map(fn ($m) => CutoverUi::label((string) $m))->first())->persistent()->send();

            return;
        } catch (RecordWriteRejected|AuthorizationException|HttpExceptionInterface $e) {
            $this->forgetScopeReview();
            Notification::make()->danger()->title('No se preparó')->body($e->getMessage().' Recarga para ver lo actual.')->persistent()->send();

            return;
        }
        $this->forgetScopeReview();
        Notification::make()->success()->title('Evidencia del grupo preparada')->body('Vale por 4 horas desde tu revisión más antigua. Todavía no se pasa nada al CRM.')->send();
    }

    // ── C3 B1: opening agreements, preparation only ─────────────────────────

    public function prepareOpenings(): void
    {
        if (! self::bridgeEnabled() || ! $this->openingPin) {
            return;
        }
        try {
            app(\App\Services\Imports\PrepareCutoverOpeningAgreements::class)->execute(Filament::auth()->user(),
                ['reconciliation_id' => $this->openingPin['reconciliation_id'], 'expected_cohort_sha256' => $this->openingPin['cohort']], $this->openingKey);
        } catch (RecordWriteRejected|AuthorizationException|HttpExceptionInterface|ValidationException $e) {
            $this->openingKey = (string) Str::uuid();
            $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            Notification::make()->danger()->title('No se prepararon los acuerdos')->body($msg.' Recarga para ver lo actual.')->persistent()->send();

            return;
        }
        $this->openingKey = (string) Str::uuid();
        Notification::make()->success()->title('Acuerdos de apertura preparados')->body('Es solo preparación: no autoriza cobros ni pasa nada al CRM.')->send();
    }

    /** Latest prepared set as Codex's read returns it, with each party's title; ['error' => …] when it cannot be read. */
    public function openings(): ?array
    {
        if (! self::bridgeEnabled()) {
            return null;
        }
        $id = \App\Models\CutoverOpeningSet::query()->max('id');
        if (! $id) {
            return null;
        }
        try {
            $set = app(\App\Services\Imports\ReadCutoverOpeningAgreements::class)->execute(Filament::auth()->user(), (int) $id);
        } catch (RecordWriteRejected|AuthorizationException|HttpExceptionInterface $e) {
            return ['error' => $e->getMessage()];
        }
        $titles = \App\Models\Event::whereIn('id', collect($set['items'])->pluck('event_id'))->pluck('title', 'id');
        $set['items'] = collect($set['items'])->map(fn ($i) => [...$i, 'title' => $titles[$i['event_id']] ?? 'Fiesta '.$i['event_id']])->all();

        return $set;
    }
}
