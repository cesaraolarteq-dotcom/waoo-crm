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
}
