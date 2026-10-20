<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, User};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Quotes\QuoteProtocol;
use App\Services\Records\{RecordActor, SaveCustomer};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};

/** Null is an explicitly provisional name, never an invented identity or verified contact. */
final class SaveQuickBookingCustomer
{
    public function execute(User $actor, array $input, ?int $customerId = null): Customer
    {
        Sandbox::require(); $actor = RecordActor::authorize($actor);
        foreach (['name', 'phone', 'email'] as $field) {
            if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]) === '' ? null : trim($input[$field]);
        }
        $data = QuoteProtocol::input($input, ['name'=>'nullable|string|max:255','phone'=>'nullable|string|max:40',
            'email'=>'nullable|email|max:255','locale'=>['required',Rule::in(['es','en'])],
            'expected_version'=>$customerId ? 'required|integer|min:1' : 'prohibited']);
        return DB::transaction(function () use ($actor, $data, $customerId) {
            RecordActor::authorize($actor);
            $customer = $customerId ? Customer::whereKey($customerId)->lockForUpdate()->firstOrFail() : new Customer();
            if ($customerId) RecordWriteRejected::unless($customer->version === (int) $data['expected_version'],409,'La ficha cambió. Recarga antes de guardar.');
            $values = [...($customerId ? $customer->only(['name','phone','email','locale']) : ['name'=>null,'phone'=>null,'email'=>null]),
                ...array_diff_key($data,['expected_version'=>true])];
            if ((!$customerId || ($values['name'] ?? null) === null) && blank($values['phone']) && blank($values['email'])) {
                throw ValidationException::withMessages(['phone'=>'Escribe su teléfono o su correo.']);
            }
            if ((!$customerId || ($values['name'] ?? null) === null) && blank($values['email'])) {
                $digits = preg_replace('/\D/', '', (string) $values['phone']);
                if (strlen($digits) < 7 || strlen($digits) > 15 || !preg_match('/^[+0-9().\s-]+$/D', (string) $values['phone'])) {
                    throw ValidationException::withMessages(['phone'=>'Revisa el teléfono o escribe un correo válido.']);
                }
            }
            if (($values['name'] ?? null) !== null) return app(SaveCustomer::class)->execute($actor,
                [...$values,...($customerId?['expected_version'=>$data['expected_version']]:[])],$customerId);
            if ($customerId && $customer->name !== null) throw ValidationException::withMessages(['name'=>'Una clienta con nombre no se convierte en una identidad provisional.']);
            if (blank($values['phone']) && blank($values['email'])) throw ValidationException::withMessages(['phone'=>'Escribe su teléfono o su correo.']);
            $before = $customer->getAttributes();
            $customer->fill($values);
            if ($customerId) $customer->version++;
            $customer->save();
            Audit::recordFor($actor,'customers.'.($customerId?'updated':'created'),$customer,$before,[...$customer->getAttributes(),'identity_provisional'=>true]);
            return $customer->fresh();
        },3);
    }
}
