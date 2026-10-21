<?php
namespace App\Services\Booking;
use App\Models\User;
final class ReleaseQuickStripeLease
{
    public function execute(User $actor,int $eventId,array $input,string $key):array
    {
        QuickStripeLeases::actor($actor);
        $data=QuickStripeLeases::input($input,['expected_event_version'=>'required|integer|min:1','expected_lease_version'=>'required|integer|min:1','reason'=>'required|string|min:10|max:1000']);
        return app(CloseQuickStripeLease::class)->execute($actor,$eventId,$data,$key,false);
    }
}
