<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Customer;
use App\Models\PriceVersion;
use App\Models\Resource;
use App\Models\ServiceVariant;
use Illuminate\Http\Request;
class CoreController extends Controller
{
    private const MODELS = ['customers' => Customer::class, 'characters' => Character::class, 'resources' => Resource::class, 'variants' => ServiceVariant::class];
    public function index(Request $request, string $entity)
    {
        $query = self::MODELS[$entity]::query()->orderBy('id');
        return $query->paginate(50);
    }
    private const SAVERS = [
        'customers' => \App\Services\Records\SaveCustomer::class,
        'characters' => \App\Services\Records\SaveCharacter::class,
        'resources' => \App\Services\Records\SaveResource::class,
        'variants' => \App\Services\Records\SaveVariant::class,
    ];
    public function store(Request $request, string $entity)
    {
        return response()->json(app(self::SAVERS[$entity])->execute($request->user(), $request->all()), 201);
    }
    public function update(Request $request, int $id, string $entity)
    {
        return app(self::SAVERS[$entity])->execute($request->user(), $request->all(), $id);
    }
    public function price(Request $request, ServiceVariant $variant)
    {
        return response()->json(app(\App\Services\Records\AddPriceVersion::class)->execute($request->user(), $variant->id, $request->all()), 201);
    }
    public function prices(ServiceVariant $variant)
    {
        return PriceVersion::where('service_variant_id', $variant->id)->orderByDesc('id')->get();
    }
    public function block(Request $request, Resource $resource)
    {
        return response()->json(app(\App\Services\Records\BlockResource::class)->execute($request->user(), $resource->id, $request->all()), 201);
    }
    public function compatibility(Request $request)
    {
        $data = \App\Services\Records\RecordInput::validate($request->all(), ['staff_id' => 'required|integer', 'costume_id' => 'required|integer']);
        return response()->json(app(\App\Services\Records\LinkCostumeCompatibility::class)->execute($request->user(), $data['staff_id'], $data['costume_id']));
    }

    public function unlinkCompatibility(Request $request)
    {
        $data = \App\Services\Records\RecordInput::validate($request->all(), ['staff_id' => 'required|integer', 'costume_id' => 'required|integer', 'reason' => 'required|string']);
        return response()->json(app(\App\Services\Records\UnlinkCostumeCompatibility::class)->execute($request->user(), $data['staff_id'], $data['costume_id'], $data['reason']));
    }
}
