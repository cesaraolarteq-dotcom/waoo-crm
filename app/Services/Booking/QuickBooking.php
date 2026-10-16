<?php
namespace App\Services\Booking;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry};

final readonly class QuickBooking
{
    public function __construct(
        public int $id,
        public Event $event,
        public Customer $customer,
        public FinancialRevision $financial_revision,
        public LedgerEntry $receipt,
        public array $finance,
        public ?string $portal_url,
        public string $portal_expires_at,
        public bool $portal_link_already_shown,
        public bool $link_issue_pending,
    ) {}
}
