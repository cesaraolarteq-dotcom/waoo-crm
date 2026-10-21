<?php
namespace App\Services\Booking;
use App\Models\User;
final class ExpireQuickStripeLease
{
    public function execute(User $actor,int $eventId,array $input,string $key):array
    {
        QuickStripeLeases::actor($actor);
        $data=QuickStripeLeases::input($input,['expected_lease_version'=>'required|integer|min:1']);
        return app(CloseQuickStripeLease::class)->execute($actor,$eventId,$data,$key,true);
    }
}
