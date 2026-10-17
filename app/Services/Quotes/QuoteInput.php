<?php
namespace App\Services\Quotes;

use App\Rules\ExplicitOffset;
use App\Services\Finance\Sandbox;
use App\Services\Records\{RecordActor, RecordInput};
use App\Models\User;
use Illuminate\Validation\{Rule, ValidationException};

final class QuoteInput
{
    public static function actor(User $actor): User
    {
        Sandbox::require('sandbox_quotes'); Sandbox::require();
        return RecordActor::authorize($actor);
    }
    public static function validate(array $input, bool $create): array
    {
        $rules = [
            ...($create ? ['event_id' => 'required|integer|min:1', 'lead_id' => 'sometimes|nullable|integer|exists:leads,id'] : ['expected_version' => 'required|integer|min:1']),
            'expected_event_version' => 'required|integer|min:1',
            'locale' => ['sometimes', Rule::in(['es','en'])], 'owner_note' => 'sometimes|nullable|string|max:2000',
            'valid_until' => ['sometimes','required','date', new ExplicitOffset],
            'discounts' => ($create ? 'sometimes' : 'present').'|array|max:5',
            'discounts.*' => 'required|array:label,amount_cents,reason,expires_at',
            'discounts.*.label' => 'required|string|min:3|max:255',
            'discounts.*.amount_cents' => 'required|integer|min:1|max:100000000',
            'discounts.*.reason' => 'required|string|min:10|max:1000',
            'discounts.*.expires_at' => ['sometimes','nullable','date', new ExplicitOffset],
        ];
        $allowed = array_filter(array_keys($rules), fn ($key) => !str_contains($key, '.'));
        foreach (array_diff(array_keys($input), $allowed) as $unknown) throw ValidationException::withMessages([$unknown => 'Este campo no se acepta; los importes y aprobaciones se calculan en el servidor.']);
        $data = RecordInput::validate($input, $rules);
        foreach (['event_id','lead_id','expected_version','expected_event_version'] as $key) if (isset($data[$key])) $data[$key] = (int) $data[$key];
        $data['discounts'] = array_values($data['discounts'] ?? []);
        foreach ($data['discounts'] as &$discount) $discount['amount_cents'] = (int) $discount['amount_cents'];
        return $data;
    }
}
