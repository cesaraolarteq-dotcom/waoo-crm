<?php
namespace App\Livewire;

use App\Exceptions\RecordWriteRejected;
use App\Models\User;
use App\Services\Booking\{HoldQuickBookingCostumes, QuickCostumeHolds, ReadQuickBookingCostumeHold, ReleaseQuickBookingCostumes};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class QuickCostumeHoldPanel extends Component
{
    #[Locked] public int $eventId;
    #[Locked] public array $hold = [];
    #[Locked] public string $attemptKey;
    #[Locked] public string $releaseKey;
    #[Locked] public ?array $releaseInput = null;
    public string $reason = '';
    #[Locked] public ?string $problem = null;

    public function mount(int $eventId): void
    {
        $this->eventId = $eventId;
        $this->reviewLatest();
    }
    private function actor(): User
    {
        $actor = auth()->user();
        if (!$actor) throw new AuthorizationException();
        return QuickCostumeHolds::actor($actor);
    }
    private function resetIntents(): void
    {
        $this->attemptKey = (string)Str::uuid();$this->releaseKey = (string)Str::uuid();$this->releaseInput = null;
    }
    public function reviewLatest(): void
    {
        $actor = $this->actor();
        try {
            $this->hold = app(ReadQuickBookingCostumeHold::class)->execute($actor, $this->eventId);
            $this->problem = null;
        } catch (AuthorizationException $e) { throw $e; }
        catch (\Throwable) {
            $this->hold = ['event_id'=>$this->eventId,'event_version'=>0,'version'=>0,'state'=>'review_required','issues'=>['hold_unavailable'],'rows'=>[],'characters'=>[],'staff_confirmed'=>false];
            $this->problem = 'No se pudo comprobar el apartado. Actualiza para revisar; no vuelvas a registrar el depósito.';
        }
        $this->resetIntents();$this->resetValidation();
    }
    public function attempt(): void
    {
        $actor = $this->actor();
        try {
            $this->hold = app(HoldQuickBookingCostumes::class)->execute($actor, $this->eventId, [
                'expected_event_version'=>$this->hold['event_version'], 'expected_hold_version'=>$this->hold['version'], 'characters'=>$this->hold['characters'],
            ], $this->attemptKey);
            $this->resetIntents();$this->problem = null;
        } catch (RecordWriteRejected $e) { $this->problem = $e->getMessage().' Actualiza y revisa antes de intentarlo de nuevo.'; }
        catch (AuthorizationException $e) { throw $e; }
        catch (\Throwable) { $this->problem = 'No se pudo comprobar el apartado. El depósito sigue registrado. Puedes reintentar; no vuelvas a registrar el pago.'; }
    }
    public function releaseHold(): void
    {
        $actor = $this->actor();
        if ($this->releaseInput === null) {
            $this->reason = trim($this->reason);
            $this->validate(['reason'=>'required|string|min:10|max:1000'], [], ['reason'=>'motivo']);
            $this->releaseInput = ['expected_event_version'=>$this->hold['event_version'], 'expected_hold_version'=>$this->hold['version'], 'reason'=>$this->reason];
        }
        try {
            $this->hold = app(ReleaseQuickBookingCostumes::class)->execute($actor, $this->eventId, $this->releaseInput, $this->releaseKey);
            $this->resetIntents();$this->reason = '';$this->problem = null;
        } catch (RecordWriteRejected $e) { $this->problem = $e->getMessage().' Actualiza y revisa antes de intentarlo de nuevo.'; }
        catch (AuthorizationException $e) { throw $e; }
        catch (\Throwable) { $this->problem = 'No se pudo comprobar la liberación. Reintenta la misma solicitud o actualiza para revisar. El pago no se modifica.'; }
    }
    public function render()
    {
        $this->actor();
        return view('livewire.quick-costume-hold-panel');
    }
}
