<?php
namespace App\Services\Leads;

use App\Models\{Customer, Lead, User};
use App\Services\Records\RecordInput;
use Illuminate\Validation\Rule;

final class ReadLeads
{
    public function index(User $actor, array $input)
    {
        LeadAccess::owner($actor);
        $data = RecordInput::validate($input, ['status' => ['sometimes', Rule::in(['new','contacted','quoted','booked','lost'])], 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $query = Lead::with('submission')->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")->orderByDesc('id');
        if (isset($data['status'])) $query->where('status', $data['status']);
        $page = $query->paginate($data['per_page'] ?? 25, ['*'], 'page', $data['page'] ?? 1);
        $customers = Customer::get(['id','name','email','phone']);
        return $page->through(fn ($lead) => $this->row($lead, $customers));
    }
    public function show(User $actor, int $id): array
    {
        LeadAccess::owner($actor);
        return $this->row(Lead::with('submission')->findOrFail($id), Customer::get(['id','name','email','phone']));
    }
    private function row(Lead $lead, $customers): array
    {
        $payload = $lead->submission->payload; $hints = [];
        $email = mb_strtolower(trim($payload['email'])); $phone = $this->phone($payload['phone']);
        foreach ($customers as $customer) {
            $by = [];
            if ($email !== '' && mb_strtolower(trim((string) $customer->email)) === $email) $by[] = 'email';
            if ($phone !== null && $phone === $this->phone($customer->phone)) $by[] = 'phone';
            if ($by) $hints[] = ['customer_id' => $customer->id, 'name' => $customer->name, 'matched_by' => $by];
            if (count($hints) >= 20) break;
        }
        return [...$lead->toArray(), 'duplicate_hints' => $hints];
    }
    private function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) $digits = substr($digits, 1);
        return strlen($digits) >= 7 ? $digits : null;
    }
}
