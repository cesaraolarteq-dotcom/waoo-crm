<?php
namespace App\Console\Commands;

use App\Services\Changes\RescheduleNotices;
use Illuminate\Console\Command;

class SendRescheduleNotices extends Command
{
    protected $signature = 'waoo:reschedule-notices';
    protected $description = 'Tell clients to sign the contract of their new date (email + text) and remind once after 48 h; nothing while mail/SMS are off';
    public function handle(RescheduleNotices $notices): int
    {
        $r = $notices->sendDue();
        $this->info("Notices: {$r['notices']} · reminders: {$r['reminders']} · held (mail/SMS off): {$r['held']} · no active link: {$r['no_link']}");
        return self::SUCCESS;
    }
}
