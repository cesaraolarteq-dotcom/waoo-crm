<?php
namespace App\Services\Booking;
final readonly class UnpaidQuickBooking {
    public function __construct(public int $id, public \App\Models\Event $event, public \App\Models\Customer $customer,
        public \App\Models\FinancialRevision $financial_revision, public array $finance, public ?string $portal_url,
        public string $portal_expires_at, public array $costume_hold, public bool $portal_link_already_shown = false,
        public bool $link_issue_pending = false, public string $payment_state = 'awaiting_payment') {}
}
