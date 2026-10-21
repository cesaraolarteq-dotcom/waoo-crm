<?php
namespace App\Filament\Pages;
use Filament\Pages\Page;
use Filament\Facades\Filament;
use App\Services\History\{HistoryAccess,ReadHoneyBookArchiveReport};
final class HoneyBookArchiveReview extends Page
{
    protected static string|\UnitEnum|null $navigationGroup='Operación';
    protected static ?string $navigationLabel='Saldos y origen HoneyBook';
    protected static ?string $slug='honeybook-revision';
    protected static ?string $title='Saldos pendientes de HoneyBook';
    protected string $view='filament.pages.honeybook-archive-review';
    public static function canAccess():bool {try{if(!Filament::auth()->user())return false;HistoryAccess::actor(Filament::auth()->user());return true;}catch(\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException){return false;}}
    public function report():array{return app(ReadHoneyBookArchiveReport::class)->execute(Filament::auth()->user());}
}
