<?php
namespace App\Filament\Pages;
use App\Models\HoneyBookSupplement;
use App\Services\HoneyBook\SupplementAccess;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\WithPagination;
final class HoneyBookInformation extends Page
{
    use WithPagination;
    protected static string|\UnitEnum|null $navigationGroup = 'Operación';
    protected static ?string $navigationLabel = 'Información por organizar';
    protected static ?string $title = 'Información de HoneyBook por organizar';
    protected static ?string $slug = 'honeybook-informacion';
    protected string $view = 'filament.pages.honeybook-information';
    public static function canAccess(): bool {
        try { SupplementAccess::check(Filament::auth()->user()); return true; }
        catch (\Illuminate\Auth\Access\AuthorizationException) { return false; }
    }
    public function entries(): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        SupplementAccess::check(Filament::auth()->user());
        return HoneyBookSupplement::orderByDesc('id')->paginate(25);
    }
}
