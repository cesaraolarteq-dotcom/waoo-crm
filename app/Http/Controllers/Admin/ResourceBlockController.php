<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Rules\ExplicitOffset;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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
        $data = \App\Services\Records\RecordInput::validate($request->all(), ['reason' => 'required|string']);
        return app(\App\Services\Records\ReleaseBlock::class)->execute($request->user(), $resource->id, $block, $data['reason']);
    }
}
