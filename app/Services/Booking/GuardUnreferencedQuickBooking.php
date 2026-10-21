<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, QuickBookingRecord};
use App\Services\Finance\ZelleReceivedDate;
use Carbon\CarbonImmutable;

/** Called after deterministic owner locks, before creating an event or recording money. */
final class GuardUnreferencedQuickBooking
{
    public function check(Customer $customer, array $data): void
    {
        [$start, $end] = ZelleReceivedDate::bounds($data['deposit']);
        $candidates = QuickBookingRecord::query()->join('events', 'events.id', '=', 'quick_bookings.event_id')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'quick_bookings.receipt_id')
            ->where('events.starts_at', CarbonImmutable::parse($data['starts_at'])->utc())
            ->where('events.ends_at', CarbonImmutable::parse($data['ends_at'])->utc())
            ->where('ledger_entries.environment', 'test')->where('ledger_entries.kind', 'receipt')->where('ledger_entries.method', 'zelle')
            ->where('ledger_entries.currency', 'USD')->where('ledger_entries.amount_cents', $data['deposit']['amount_cents'])
            ->where('ledger_entries.received_at', '>=', $start)->where('ledger_entries.received_at', '<', $end)
            ->pluck('quick_bookings.customer_id')->unique();
        foreach (Customer::whereIn('id', $candidates)->get() as $previous) {
            $same = $previous->id === $customer->id
                || (filled($customer->email) && mb_strtolower($previous->email ?? '') === mb_strtolower($customer->email))
                || ($this->phone($customer->phone) !== null && $this->phone($previous->phone) === $this->phone($customer->phone));
            RecordWriteRejected::unless(!$same, 409, 'Hay una reserva y un pago similares. Revisa el historial; si es otro pago, usa su referencia bancaria real.');
        }
    }

    private function phone(?string $value): ?string
    {
        if (!$value || !preg_match('/^\+?[0-9 () .-]+$/', $value)) return null;
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) $digits = substr($digits, 1);
        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }
}
