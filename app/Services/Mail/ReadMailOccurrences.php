<?php
namespace App\Services\Mail;

use App\Models\{MailOccurrence, MailOccurrenceState, User};
use App\Services\Records\RecordInput;

final class ReadMailOccurrences
{
    public function execute(User $actor, array $input): array
    {
        MailGuard::actor($actor);
        $data = RecordInput::validate($input, ['event_id' => 'sometimes|integer|min:1', 'lead_id' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $query = MailOccurrence::query();
        foreach (['event_id', 'lead_id'] as $field) if (isset($data[$field])) $query->where($field, $data[$field]);
        $size = (int) ($data['per_page'] ?? 25); $total = $query->count(); $last = max(1, (int) ceil($total / $size));
        $page = min($last, (int) ($data['page'] ?? 1));
        $rows = $query->orderByDesc('id')->offset(($page - 1) * $size)->limit($size)->get();
        $states = MailOccurrenceState::whereIn('mail_occurrence_id', $rows->modelKeys())->get()->keyBy('mail_occurrence_id');
        return ['data' => $rows->map(function ($row) use ($states) {
            $state = $states->get($row->id);
            return [...$row->only(['id', 'key', 'event_id', 'lead_id', 'occurred_at']),
                'status' => $state->status, 'version' => $state->version, 'reason' => $state->reason,
                'outbound_message_id' => $state->outbound_message_id];
        })->all(), 'page' => $page, 'per_page' => $size, 'total' => $total, 'last_page' => $last];
    }
}
