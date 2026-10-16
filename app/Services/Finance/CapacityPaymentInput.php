<?php
namespace App\Services\Finance;

use App\Services\Records\RecordInput;

final class CapacityPaymentInput
{
    public static function validate(array $input): array
    {
        $rules = \App\Services\Records\ConfirmCapacity::rules();
        foreach (array_keys($rules) as $field) if ($field === 'payment' || str_starts_with($field, 'payment.')) unset($rules[$field]);
        $rules['financial_revision_id'] = 'required|integer|min:1';
        return RecordInput::validate($input, $rules);
    }
}
