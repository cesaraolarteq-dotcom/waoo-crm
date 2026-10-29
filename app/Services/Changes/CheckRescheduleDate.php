<?php
namespace App\Services\Changes;

use App\Models\{Event, User};
use App\Services\Availability\{AdmitEventCapacity, CheckWebsiteCandidates};
use App\Services\Records\RecordActor;
use App\Services\Routing\DriveMinutes;
use App\Support\ChicagoTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "¿Está libre esta fecha?" (CHANGE-A A2, check only; César 2026-10-29: a reschedule re-signs a new contract and the
 * $100 fee is decided case by case). The same party — package, characters, duration, setup/breakdown, address — at
 * another start, against every other commitment including HoneyBook's calendar. Read-only: no lock, hold, reservation,
 * money or message. The answer is not a promise; the change itself re-checks under locks.
 */
final class CheckRescheduleDate
{
    public const MESSAGES = [
        'available' => 'Sí está libre: caben el staff, los trajes y el equipo de esta fiesta, con su mismo paquete y duración. Todavía no se aparta nada.',
        'owner_approval_required' => 'Cabe en la agenda (trajes y equipo libres), pero falta confirmar personal o el manejo entre fiestas. Revísalo con tu equipo antes de ofrecerla.',
        'unavailable' => 'No hay cupo: ya hay 4 fiestas al mismo tiempo, 10 en el día, o un traje o equipo de esta fiesta está ocupado a esa hora.',
        'review_required' => 'No se pudo comprobar automáticamente (calendario de HoneyBook sin respuesta o datos de la fiesta incompletos). Revisa la agenda a mano.',
    ];

    /** @return array{state:string,message:string,starts_at:string,ends_at:string} */
    public function execute(User $actor, int $eventId, array $input): array
    {
        RecordActor::authorize($actor);
        $now = CarbonImmutable::now('America/Chicago');
        $v = Validator::make($input, [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$now->toDateString(), 'before:'.$now->addYears(2)->toDateString()],
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/D'],
            'offset_choice' => ['sometimes', 'nullable', 'in:cdt,cst'],
        ], ['date.after_or_equal' => 'Elige una fecha de hoy en adelante.', 'date.before' => 'Elige una fecha dentro de los próximos 2 años.', 'time.regex' => 'Escribe la hora como 14:30.']);
        if ($v->fails()) throw new ValidationException($v);
        $event = Event::findOrFail($eventId);
        if ($event->status === 'cancelled') throw ValidationException::withMessages(['date' => 'Esta fiesta está cancelada.']);
        if (! $event->services()->exists()) throw ValidationException::withMessages(['date' => 'La fiesta no tiene paquete; no se puede revisar.']);
        $start = CarbonImmutable::parse(ChicagoTime::toIso($input['date'], $input['time'], $input['offset_choice'] ?? null, 'time'));
        if ($start <= $now) throw ValidationException::withMessages(['time' => 'Esa hora ya pasó.']);
        $calendar = CheckWebsiteCandidates::busy($start->subDay(), $start->addDays(2));
        // The owner waits a little longer than the website: up to 3 uncached drives, 2 s each.
        $state = AdmitEventCapacity::preview($event, $start, $calendar,
            app()->bound('waoo.availability.drive') ? app('waoo.availability.drive') : [new DriveMinutes(3, 2.0), 'between']);
        $end = $start->addSeconds(CarbonImmutable::instance($event->starts_at)->diffInSeconds(CarbonImmutable::instance($event->ends_at)));
        return ['state' => $state, 'message' => self::MESSAGES[$state], 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()];
    }
}
