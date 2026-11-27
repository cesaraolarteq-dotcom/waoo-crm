<?php
namespace App\Console\Commands;

use App\Services\Google\GoogleContacts;
use Illuminate\Console\Command;

class SyncGoogleContacts extends Command
{
    protected $signature = 'waoo:contacts';
    protected $description = 'Save clients who booked in the owner\'s Google Contacts (Clientes Waoo).';

    public function handle(GoogleContacts $contacts): int
    {
        $this->line(json_encode($contacts->sync()));
        return self::SUCCESS;
    }
}
