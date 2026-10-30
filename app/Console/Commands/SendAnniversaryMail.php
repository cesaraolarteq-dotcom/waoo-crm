<?php
namespace App\Console\Commands;

use App\Services\Campaigns\AnniversaryMail;
use Illuminate\Console\Command;

class SendAnniversaryMail extends Command
{
    protected $signature = 'waoo:anniversary-send';
    protected $description = 'Send due "One year later" emails and day-6 reminders (only when mail and the campaign switch are on)';
    public function handle(AnniversaryMail $mail): int
    {
        if (! config('waoo.sandbox_anniversary', false)) { $this->error('WAOO_SANDBOX_ANNIVERSARY is off.'); return self::FAILURE; }
        $r = $mail->sendDue();
        $this->info("Sent: {$r['sent']} · reminders: {$r['reminders']} · held (mail off): {$r['held']}");
        return self::SUCCESS;
    }
}
