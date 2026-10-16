<?php
namespace App\Services\Leads;

use App\Services\Finance\Sandbox;
use App\Services\Records\RecordActor;
use App\Models\User;

final class LeadAccess
{
    public static function owner(User $actor): User
    {
        Sandbox::require('sandbox_lead_intake');
        return RecordActor::authorize($actor);
    }
}
