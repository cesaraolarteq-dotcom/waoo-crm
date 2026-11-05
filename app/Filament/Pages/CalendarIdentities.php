<?php
namespace App\Filament\Pages;

use App\Models\Event;
use App\Services\Availability\{BindCalendarIdentity,ReviewCalendarIdentity};
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/** Private, explicit reconciliation. Selecting a similar time never associates an event automatically. */
class CalendarIdentities extends Page
{
    protected static ?string $navigationLabel='Revisar calendario';
    protected static string|\UnitEnum|null $navigationGroup='Operación';
    protected static ?string $title='Revisar calendario de HoneyBook';
    protected static ?string $slug='revisar-calendario';
    protected string $view='filament.pages.calendar-identities';
    public ?string $eventId=null;
    public ?string $identity=null;
    public bool $verified=false;
    public string $reason='';
    #[Locked] public ?array $review=null;

    public static function canAccess(): bool
    {
        $u=Filament::auth()->user();
        return app()->environment(['local','testing','staging']) && config('waoo.sandbox_imports',false)
            && $u?->active && $u->role==='owner_admin' && $u->two_factor_secret && $u->two_factor_confirmed_at;
    }

    public function mount(): void { abort_unless(static::canAccess(),403); }

    public function parties(): \Illuminate\Support\Collection
    {
        abort_unless(static::canAccess(),403);
        return Event::whereIn('id',\App\Models\ImportedProjectLink::select('event_id'))->where('status','!=','cancelled')
            ->where('ends_at','>',now())->orderBy('starts_at')->limit(200)->get();
    }

    public function inspect(): void
    {
        abort_unless(static::canAccess(),403);
        $this->validate(['eventId'=>'required|integer|min:1']);
        $this->identity=null;$this->verified=false;$this->reason='';$this->review=null;
        $this->resetErrorBag();
        try{$this->review=app(ReviewCalendarIdentity::class)->read(Filament::auth()->user(),(int)$this->eventId);}
        catch(\App\Exceptions\RecordWriteRejected $e){$this->addError('calendar',$e->getMessage());}
    }

    public function associate(): void
    {
        abort_unless(static::canAccess(),403);
        $this->validate(['identity'=>'required|string|regex:/^[a-f0-9]{64}$/D','verified'=>'accepted','reason'=>'required|string|min:10|max:1000']);
        abort_unless($this->review && (int)$this->eventId===$this->review['event_id'],409);
        try{app(BindCalendarIdentity::class)->execute(Filament::auth()->user(),$this->review['event_id'],[
            'expected_event_version'=>$this->review['expected_event_version'],'uid_sha256'=>$this->identity,
            'expected_scope_sha256'=>$this->review['scope_sha256'],
            'verified_same_event'=>$this->verified,'reason'=>$this->reason]);}
        catch(\App\Exceptions\RecordWriteRejected $e){$this->addError('calendar',$e->getMessage());return;}
        $this->inspect();Notification::make()->success()->title('Asociación guardada')->send();
    }

    public function remove(): void
    {
        abort_unless(static::canAccess(),403);
        $this->validate(['reason'=>'required|string|min:10|max:1000']);
        abort_unless($this->review && $this->review['binding_id'] && (int)$this->eventId===$this->review['event_id'],409);
        try{app(ReviewCalendarIdentity::class)->remove(Filament::auth()->user(),$this->review['event_id'],
            $this->review['expected_event_version'],(int)$this->review['binding_id'],$this->reason,$this->review['scope_sha256']);}
        catch(\App\Exceptions\RecordWriteRejected $e){$this->addError('calendar',$e->getMessage());return;}
        $this->inspect();Notification::make()->success()->title('Asociación retirada; ambas entradas vuelven a contar')->send();
    }
}
