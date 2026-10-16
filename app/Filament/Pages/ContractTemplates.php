<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\{DocumentsGate, Labels};
use App\Models\{ContractTemplateVersion, User};
use App\Services\Documents\{ActivateTemplateVersion, CreateTemplateVersion};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Textarea};
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

// Contract template versions (DOC-01, sandbox). New text = new numbered version; signed and issued contracts keep theirs.
class ContractTemplates extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static string|\UnitEnum|null $navigationGroup = 'Vender';
    protected static ?string $navigationLabel = 'Contrato';
    protected static ?int $navigationSort = 30;
    protected static ?string $slug = 'contrato';
    protected static ?string $title = 'Plantilla del contrato';
    protected string $view = 'filament.pages.contract-templates';

    #[Locked]
    public string $key = '';

    public static function canAccess(): bool
    {
        return parent::canAccess() && DocumentsGate::enabled();
    }

    public function mount(): void
    {
        $this->key = (string) Str::uuid();
    }

    public function getSubheading(): ?string
    {
        return 'Modo prueba. El contrato va en inglés. Antes de usarlo con clientas reales, que lo revise un abogado.';
    }

    public function versions()
    {
        return ContractTemplateVersion::query()->orderByDesc('version')->get()->map(fn ($t) => (object) [
            'id' => $t->id, 'version' => $t->version, 'state' => $t->state, 'note' => $t->change_note,
            'by' => User::whereKey($t->created_by)->value('name'), 'when' => Labels::when($t->created_at),
            'approved' => $t->approved_at ? Labels::when($t->approved_at).' · '.User::whereKey($t->approved_by)->value('name') : null,
            'terms' => $t->terms_html, 'sha' => $t->source_sha256,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newVersion')->label('Nueva versión del texto')->icon('heroicon-o-plus')
                ->modalHeading('Nueva versión de los términos')
                ->modalDescription('Pega los términos tal como deben quedar (en inglés). Solo texto y formato básico. Los contratos ya emitidos o firmados no cambian.')
                ->schema([
                    Textarea::make('terms_html')->label('Términos')->required()->rows(14)
                        ->helperText('Formato permitido: <h3>, <p>, <ul>/<li>, <strong>. Sin estilos ni enlaces.'),
                    Textarea::make('change_note')->label('¿Qué cambió y por qué?')->required()->minLength(10)->maxLength(2000)->rows(2),
                ])
                ->modalSubmitActionLabel('Guardar como borrador')
                ->action(function (array $data, Action $action) {
                    $this->run($action, fn () => app(CreateTemplateVersion::class)->execute(Filament::auth()->user(), $data, $this->key), 'Versión guardada como borrador');
                    $this->key = (string) Str::uuid();
                }),
        ];
    }

    public function activateAction(): Action
    {
        return Action::make('activate')->label('Activar esta versión')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Activar la versión '.($arguments['version'] ?? ''))
            ->modalDescription('Los contratos nuevos usarán este texto. Los ya emitidos o firmados conservan el suyo.')
            ->schema([Textarea::make('approval_note')->label('Nota de aprobación')->required()->minLength(10)->maxLength(2000)->rows(2)
                ->placeholder('Ej.: revisado por mí; pendiente revisión del abogado antes de clientas reales.')])
            ->modalSubmitActionLabel('Activar')
            ->action(function (array $data, array $arguments, Action $action) {
                $this->run($action, fn () => app(ActivateTemplateVersion::class)->execute(Filament::auth()->user(), (int) $arguments['id'],
                    ['expected_source_sha256' => $arguments['sha'], 'approval_note' => $data['approval_note']]), 'Versión activada');
            });
    }

    private function run(Action $action, \Closure $call, string $success): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('No se guardó')->body(collect($e->errors())->flatten()->first())->persistent()->send();
            $action->halt();
        } catch (RecordWriteRejected|AuthorizationException $e) {
            Notification::make()->danger()->title('No se guardó')->body($e->getMessage())->persistent()->send();
            $action->halt();
        }
        Notification::make()->success()->title($success)->send();
    }
}
