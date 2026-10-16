<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Resource, ResourceBlock};
use App\Rules\ExplicitOffset;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ResourceBlockController extends Controller
{
    public function index(Request $request, Resource $resource)
    {
        $data = $request->validate([
            'state' => ['sometimes', Rule::in(['active', 'released', 'all'])],
            'from' => ['nullable', 'date', new ExplicitOffset],
            'to' => ['nullable', 'date', new ExplicitOffset],
        ]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : null;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc() : null;
        if ($from && $to && $to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['to' => 'La fecha final debe ser posterior a la inicial.']);
        }
        $query = ResourceBlock::where('resource_id', $resource->id)->orderBy('starts_at')->orderBy('id');
        match ($data['state'] ?? 'active') {
            'active' => $query->whereNull('released_at'),
            'released' => $query->whereNotNull('released_at'),
            default => null,
        };
        if ($from) $query->where('ends_at', '>', $from);
        if ($to) $query->where('starts_at', '<', $to);
        return $query->paginate(50);
    }

    public function release(Request $request, Resource $resource, int $block)
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:1000']);
        return DB::transaction(function () use ($resource, $block, $data) {
            Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $record = ResourceBlock::where('resource_id', $resource->id)->whereKey($block)->lockForUpdate()->firstOrFail();
            if ($record->released_at !== null) {
                abort_unless($record->release_reason === $data['reason'], 409, 'El bloqueo ya fue levantado con otro motivo. Consulta el historial.');
                return $record;
            }
            $before = $record->getAttributes();
            $record->released_at = now();
            $record->released_by = auth()->id();
            $record->release_reason = $data['reason'];
            $record->save();
            Audit::record('resource.block_released', $record, $before, $record->getAttributes());
            return $record;
        }, 3);
    }
}
