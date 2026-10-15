<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Character, Customer, Resource, ResourceBlock, ResourceReservation, ServiceVariant, PriceVersion};
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class CoreController extends Controller
{
    private const MODELS = ['customers' => Customer::class, 'characters' => Character::class, 'resources' => Resource::class, 'variants' => ServiceVariant::class];
    public function index(Request $request, string $entity)
    {
        $query = self::MODELS[$entity]::query()->orderBy('id');
        return $query->paginate(50);
    }
    public function store(Request $request, string $entity)
    {
        $data = $this->validateData($request, $entity);
        return DB::transaction(function () use ($entity, $data) {
            $model = self::MODELS[$entity]::create($data);
            Audit::record($entity . '.created', $model, [], $model->getAttributes());
            return response()->json($model, 201);
        });
    }
    public function update(Request $request, int $id, string $entity)
    {
        $data = $this->validateData($request, $entity, $id);
        $version = in_array($entity, ['resources', 'variants']) ? $request->validate(['expected_version' => 'required|integer|min:1'])['expected_version'] : null;
        return DB::transaction(function () use ($entity, $id, $data, $version) {
            $model = self::MODELS[$entity]::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($version !== null) {
                abort_unless($model->version === $version, 409, 'La ficha cambió. Actualiza antes de guardar.');
            }
            if ($entity === 'resources') {
                abort_if(ResourceReservation::where('resource_id', $id)->where('ends_at', '>', now())->exists(), 409, 'Reasigna primero los compromisos de este recurso.');
            }
            $before = $model->getAttributes();
            $model->fill($data);
            if ($version !== null) {
                $model->version++;
            }
            $model->save();
            Audit::record($entity . '.updated', $model, $before, $model->getAttributes());
            return $model;
        });
    }
    public function price(Request $request, ServiceVariant $variant)
    {
        $data = $request->validate(['amount_cents' => 'required|integer|min:0|max:100000000', 'currency' => ['required', Rule::in(['USD'])]]);
        return DB::transaction(function () use ($variant, $data) {
            $price = PriceVersion::create([...$data, 'service_variant_id' => $variant->id, 'created_by' => auth()->id()]);
            Audit::record('price.version_created', $price, [], $price->getAttributes());
            return response()->json($price, 201);
        });
    }
    public function prices(ServiceVariant $variant)
    {
        return PriceVersion::where('service_variant_id', $variant->id)->orderByDesc('id')->get();
    }
    public function block(Request $request, Resource $resource)
    {
        $data = $request->validate(['starts_at' => ['required', 'date', new \App\Rules\ExplicitOffset()], 'ends_at' => ['required', 'date', 'after:starts_at', new \App\Rules\ExplicitOffset()], 'reason' => 'required|string|max:255']);
        return DB::transaction(function () use ($resource, $data) {
            Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $start = \Carbon\CarbonImmutable::parse($data['starts_at'])->utc();
            $end = \Carbon\CarbonImmutable::parse($data['ends_at'])->utc();
            $bookings = ResourceReservation::where('resource_id', $resource->id)->with('event')->orderBy('starts_at')->get();
            abort_if($bookings->contains(fn($r) => $r->event->starts_at->subMinutes($r->event->setup_minutes)->lessThan($end) && $r->event->ends_at->addMinutes($r->event->breakdown_minutes)->greaterThan($start)), 409, 'El bloqueo afecta un evento. Resuelve primero la asignación.');
            // A later block must also preserve driving intervals already reviewed
            // between the resource's current adjacent commitments.
            for ($i = 1; $i < $bookings->count(); $i++) {
                $from = $bookings[$i - 1]->event;
                $to = $bookings[$i]->event;
                $reviews = \App\Models\OperationalReview::whereIn('event_id', [$from->id, $to->id])->orderByDesc('id')->get();
                $trip = null;
                foreach ($reviews as $review) {
                    $trip = collect($review->evidence['transfers'] ?? [])->first(fn($route) => (int) $route['from_event_id'] === $from->id && (int) $route['to_event_id'] === $to->id);
                    if ($trip) {
                        break;
                    }
                }
                if ($trip && (int) $trip['travel_minutes'] > 0) {
                    $departure = $from->ends_at->addMinutes($from->breakdown_minutes);
                    $arrival = $departure->addMinutes((int) $trip['travel_minutes']);
                    abort_if($departure->lessThan($end) && $arrival->greaterThan($start), 409, 'El bloqueo afecta un traslado comprometido. Resuelve primero la asignación.');
                }
            }
            $block = ResourceBlock::create(['resource_id' => $resource->id, 'starts_at' => $start, 'ends_at' => $end, 'reason' => $data['reason']]);
            Audit::record('resource.blocked', $block, [], $block->getAttributes());
            return response()->json($block, 201);
        });
    }
    public function compatibility(Request $request)
    {
        $data = $request->validate(['staff_id' => 'required|integer|exists:resources,id', 'costume_id' => 'required|integer|exists:resources,id']);
        return DB::transaction(function () use ($data) {
            $rows = Resource::whereIn('id', [$data['staff_id'], $data['costume_id']])->orderBy('id')->lockForUpdate()->get();
            $staff = $rows->firstWhere('id', (int) $data['staff_id']);
            $costume = $rows->firstWhere('id', (int) $data['costume_id']);
            abort_unless($staff?->kind === 'staff' && $costume?->kind === 'costume', 422, 'Selecciona una persona y un traje.');
            DB::table('costume_compatibilities')->updateOrInsert($data, []);
            Audit::record('costume.compatibility_added', $costume, [], ['staff_id' => $staff->id]);
            return response()->json($data);
        });
    }
    private function validateData(Request $request, string $entity, ?int $id = null): array
    {
        $rules = match ($entity) {
            'customers' => ['name' => 'required|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'locale' => ['required', Rule::in(['es', 'en'])]],
            'characters' => ['name' => 'required|string|max:255', 'active' => 'required|boolean'],
            'resources' => ['code' => ['required', 'string', 'max:60', Rule::unique('resources')->ignore($id)], 'name' => 'required|string|max:255', 'kind' => ['required', Rule::in(['staff', 'costume', 'equipment', 'vehicle'])], 'status' => ['required', Rule::in(['active', 'maintenance', 'inactive'])], 'character_id' => 'nullable|required_if:kind,costume|integer|exists:characters,id', 'location' => 'nullable|string|max:255', 'skills' => 'present|array|max:30', 'skills.*' => 'string|max:60|distinct', 'equipment_kind' => 'nullable|required_if:kind,equipment|string|max:60', 'seats' => 'nullable|required_if:kind,vehicle|integer|min:1|max:100'],
            'variants' => ['name' => 'required|string|max:255', 'status' => ['required', Rule::in(['draft', 'active', 'retired'])], 'duration_minutes' => 'required|integer|min:1|max:1440', 'requirements' => 'present|array|max:30', 'requirements.*' => 'array:kind,quantity,skill,character_id,equipment_kind', 'requirements.*.kind' => ['required', Rule::in(['staff', 'costume', 'equipment', 'vehicle'])], 'requirements.*.quantity' => 'required|integer|min:1|max:20', 'requirements.*.skill' => 'nullable|string|max:60', 'requirements.*.character_id' => 'nullable|integer|exists:characters,id', 'requirements.*.equipment_kind' => 'nullable|string|max:60'],
        };
        $data = $request->validate($rules);
        if ($entity === 'resources' && $id) {
            abort_unless(Resource::findOrFail($id)->kind === $data['kind'], 422, 'No cambies el tipo de una unidad; crea una ficha nueva.');
        }
        if ($entity === 'variants' && $data['status'] === 'active') {
            abort_if(empty($data['requirements']), 422, 'Define requisitos antes de activar el servicio.');
        }
        if ($entity === 'variants') {
            foreach ($data['requirements'] as $req) {
                $field = match ($req['kind']) {
                    'staff' => 'skill',
                    'costume' => 'character_id',
                    'equipment' => 'equipment_kind',
                    default => null,
                };
                abort_if($field && !($req[$field] ?? null), 422, 'Completa habilidad, personaje o tipo de equipo por requisito.');
            }
        }
        return $data;
    }
}
