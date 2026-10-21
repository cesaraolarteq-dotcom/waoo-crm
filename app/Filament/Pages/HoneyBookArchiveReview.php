<?php
namespace App\Filament\Pages;
use Filament\Pages\Page;
use Filament\Facades\Filament;
use App\Services\History\{HistoryAccess,ReadHoneyBookArchiveReport,ResolveHistoricalBalance};
use Livewire\Attributes\Locked;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
final class HoneyBookArchiveReview extends Page
{
    protected static string|\UnitEnum|null $navigationGroup='Operación';
    protected static ?string $navigationLabel='Saldos y origen HoneyBook';
    protected static ?string $slug='honeybook-revision';
    protected static ?string $title='Saldos pendientes de HoneyBook';
    protected string $view='filament.pages.honeybook-archive-review';
    public const RESOLUTIONS=['paid_outside_honeybook'=>'Pagado fuera de HoneyBook (declaración del dueño)',
        'duplicate_source_file'=>'Archivo de origen duplicado','cancelled_no_balance'=>'Cancelado, sin saldo por cobrar',
        'pending_reschedule'=>'Pendiente de reprogramar'];
    #[Locked] public ?int $resolutionHistoryId=null;
    #[Locked] public array $resolutionPin=[];
    #[Locked] public ?string $resolutionKey=null;
    public string $resolutionKind='paid_outside_honeybook';
    public string $resolutionNote='';
    public static function canAccess():bool {try{if(!Filament::auth()->user())return false;HistoryAccess::actor(Filament::auth()->user());return true;}catch(\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException){return false;}}
    public function report():array{return app(ReadHoneyBookArchiveReport::class)->execute(Filament::auth()->user());}
    public function startResolution(int $historyId,int $historyVersion,int $revisionId,string $sourceHash,int $resolutionVersion):void {
        $this->resolutionHistoryId=$historyId;$this->resolutionPin=['expected_history_version'=>$historyVersion,'expected_revision_id'=>$revisionId,
            'expected_source_sha256'=>$sourceHash,'expected_resolution_version'=>$resolutionVersion];
        $this->resolutionKey=(string)Str::uuid();$this->resolutionNote='';$this->resolutionKind='paid_outside_honeybook';
    }
    public function cancelResolution():void {$this->resolutionHistoryId=null;$this->resolutionPin=[];$this->resolutionKey=null;$this->resolutionNote='';}
    public function saveResolution():void {
        if(!$this->resolutionHistoryId||!$this->resolutionPin||!$this->resolutionKey)return;
        $this->validate(['resolutionKind'=>'required|in:'.implode(',',array_keys(self::RESOLUTIONS)),'resolutionNote'=>'required|string|min:10|max:2000']);
        try{app(ResolveHistoricalBalance::class)->execute(Filament::auth()->user(),$this->resolutionHistoryId,[...$this->resolutionPin,'kind'=>$this->resolutionKind,'owner_note'=>$this->resolutionNote],$this->resolutionKey);}
        catch(\App\Exceptions\RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException $e){$this->cancelResolution();Notification::make()->danger()->title('Revisa de nuevo')->body($e->getMessage())->send();return;}
        $this->cancelResolution();Notification::make()->success()->title('Decisión guardada')->body('El saldo original se conserva. No se creó un recibo ni una reserva nueva.')->send();
    }
}
