<?php
namespace App\Services\Quotes;

use App\Models\{Quote, User};
use App\Services\Records\RecordInput;
use Illuminate\Validation\Rule;

final class ReadQuotes
{
    public function index(User $actor, array $input)
    {
        QuoteInput::actor($actor);
        $data = RecordInput::validate($input, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50',
            'status' => ['sometimes', Rule::in(['draft','sent','accepted','expired','superseded','rejected'])]]);
        $query = Quote::query()->orderByDesc('id');
        if (isset($data['status'])) {
            if ($data['status'] === 'expired') $query->where(fn ($q) => $q->where('status', 'expired')->orWhere(fn ($p) => $p->whereIn('status', ['draft','sent'])->where('valid_until', '<=', now())));
            else {
                $query->where('status', $data['status']);
                if (in_array($data['status'], ['draft','sent'], true)) $query->where('valid_until', '>', now());
            }
        }
        return $query->paginate($data['per_page'] ?? 25, ['*'], 'page', $data['page'] ?? 1)
            ->through(fn ($q) => [...$q->toArray(), 'effective_status' => $q->effectiveStatus()]);
    }
    public function show(User $actor, int $quoteId): array
    {
        QuoteInput::actor($actor); $q = Quote::findOrFail($quoteId);
        return ['quote' => [...$q->toArray(), 'effective_status' => $q->effectiveStatus()], 'revisions' => $q->revisions()->orderByDesc('revision')->get()];
    }
}
