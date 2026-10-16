<?php
namespace App\Services\Leads;

use App\Models\User;
use App\Services\Booking\{QuickBook, QuickBooking};
use App\Services\Records\RecordInput;

final class ConvertLead
{
    public function execute(User $actor, int $leadId, array $input, string $key): QuickBooking
    {
        $actor = LeadAccess::owner($actor);
        $data = RecordInput::validate($input, ['expected_version' => 'required|integer|min:1', 'booking' => 'required|array']);
        return app(QuickBook::class)->execute($actor, [...$data['booking'], 'lead_id' => $leadId, 'lead_expected_version' => (int) $data['expected_version']], $key);
    }
}
