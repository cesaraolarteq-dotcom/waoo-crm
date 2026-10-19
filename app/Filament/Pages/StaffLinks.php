<?php

namespace App\Filament\Pages;

use App\Exceptions\{PortalLinkRejected, RecordWriteRejected};
use App\Filament\Support\{Labels, StaffLinksSource};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// STAFF-01 v2 owner screen (docs/STAFF-01-API.md, owner decision 2026-10-18): each staff member has ONE personal link
// that does not change and always shows their confirmed parties of the week. César creates it once, copies it again
// whenever he needs, rotates it if it was lost (old link dies at once) or turns it off. Every action is pinned to the
// row versions this page showed (Locked), with a UUID key per intent. Opening WhatsApp/SMS is not "sent".
class StaffLinks extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;
    protected static string|\UnitEnum|null $navigationGroup = 'Operación';
    protected static ?string $navigationLabel = 'Enlaces del staff';
    protected static ?int $navigationSort = 40;
    protected static ?string $slug = 'staff-enlaces';
    protected static ?string $title = 'Enlaces del staff';
    protected string $view = 'filament.pages.staff-links';

    /** staff_id => [version, grant_id|null, grant_version|null, name, locale] as displayed. */
    #[Locked] public array $shown = [];
    #[Locked] public array $keys = [];
    public int $page = 1;
    public ?array $newLink = null;

    public static function canAccess(): bool
    {
        return parent::canAccess() && (StaffLinksSource::live() || StaffLinksSource::sample());
    }

    public function getSubheading(): ?string
    {
        return 'Cada persona tiene un solo enlace que no cambia: se lo mandas una vez y siempre muestra sus fiestas confirmadas de la semana. Sin dinero ni datos de otras personas.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->pin();
    }

    public function rows(): array
    {
        return StaffLinksSource::links(Filament::auth()->user(), $this->page);
    }

    private function pin(): void
    {
        $this->shown = [];
        foreach ($this->rows()['data'] as $r) {
            $this->shown[$r['staff_id']] = ['version' => $r['version'], 'grant_id' => $r['grant']['id'] ?? null,
                'grant_version' => $r['grant']['version'] ?? null, 'name' => $r['display_name'], 'locale' => $r['locale']];
            $this->keys[$r['staff_id']] ??= (string) Str::uuid();
        }
    }

    public static function opened(?string $iso): string
    {
        return $iso ? 'Lo abrió '.rtrim(Labels::when(\Carbon\CarbonImmutable::parse($iso)), '.') : 'Todavía no lo abre';
    }

    public function issueAction(): Action
    {
        return $this->linkAction('issue', 'Crear enlace', 'heroicon-o-link', 'primary', null);
    }

    public function revealAction(): Action
    {
        return $this->linkAction('reveal', 'Copiar enlace', 'heroicon-o-clipboard', 'gray', null);
    }

    public function rotateAction(): Action
    {
        return $this->linkAction('rotate', 'Cambiar enlace', 'heroicon-o-arrow-path', 'warning',
            'El enlace actual deja de funcionar al instante y se crea uno nuevo. Úsalo si se perdió o lo compartieron. Tendrás que mandarle el nuevo.');
    }

    public function revokeAction(): Action
    {
        return $this->linkAction('revoke', 'Apagar', 'heroicon-o-no-symbol', 'danger',
            'Ya no podrá ver sus fiestas con ese enlace. Úsalo si deja de trabajar contigo. Después puedes crearle uno nuevo.');
    }

    private function linkAction(string $what, string $label, string $icon, string $color, ?string $confirm): Action
    {
        $a = Action::make($what)->label($label)->icon($icon)->color($color)->size('sm')
            ->action(function (array $arguments) use ($what) {
                $id = (int) ($arguments['staff'] ?? 0);
                $row = $this->shown[$id] ?? null;
                if (! $row) return;
                $target = $what === 'issue' ? $id : (int) $row['grant_id'];
                $version = (int) ($what === 'issue' ? $row['version'] : $row['grant_version']);
                try {
                    $r = StaffLinksSource::act($what, Filament::auth()->user(), $target, $version, $what === 'reveal' ? null : ($this->keys[$id] ?? null));
                } catch (ValidationException $e) {
                    $this->fail(collect($e->errors())->flatten()->first(), $id);

                    return;
                } catch (RecordWriteRejected|AuthorizationException|PortalLinkRejected|HttpExceptionInterface $e) {
                    $this->fail($e->getMessage().($e instanceof RecordWriteRejected && $e->status === 409 && StaffLinksSource::live() ? ' Recarga para ver lo último.' : ''), $id);

                    return;
                }
                $this->keys[$id] = (string) Str::uuid();
                if ($what === 'revoke') {
                    $this->newLink = null;
                    Notification::make()->success()->title('Enlace apagado')->send();
                } else {
                    $this->newLink = ['url' => $r['link'], 'expires' => null, 'phone' => $r['phone'] ?? null, 'locale' => $row['locale'],
                        'heading' => 'Enlace de '.$row['name'], 'hint' => 'No vence. Puedes volver a copiarlo aquí cuando quieras.',
                        'msg_es' => StaffLinksSource::message('es', $row['name'], $r['link']), 'msg_en' => StaffLinksSource::message('en', $row['name'], $r['link'])];
                }
                $this->pin();
            });

        return $confirm ? $a->requiresConfirmation()->modalHeading($label.' de esta persona')->modalDescription($confirm)->modalSubmitActionLabel($label) : $a;
    }

    private function fail(?string $message, int $id): void
    {
        $this->keys[$id] = (string) Str::uuid();
        $this->newLink = null;
        Notification::make()->danger()->title('No se hizo el cambio')->body($message)->persistent()->send();
    }
}
