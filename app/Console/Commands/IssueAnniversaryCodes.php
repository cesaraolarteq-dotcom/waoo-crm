<?php
namespace App\Console\Commands;

use App\Services\Campaigns\AnniversaryCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class IssueAnniversaryCodes extends Command
{
    protected $signature = 'waoo:anniversary-issue {--date= : Houston date to run for (Y-m-d), default today}';
    protected $description = 'Issue "Un año después" codes for clients 46 weeks after their last birthday party (sends nothing)';
    public function handle(AnniversaryCampaign $campaign): int
    {
        if (! config('waoo.sandbox_anniversary', false)) { $this->error('WAOO_SANDBOX_ANNIVERSARY is off.'); return self::FAILURE; }
        $date = $this->option('date');
        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $date)) { $this->error('date must be Y-m-d.'); return self::FAILURE; }
        $r = $campaign->issue($date ? CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/Chicago') : CarbonImmutable::now('America/Chicago'));
        $this->info("Issued: {$r['issued']} · skipped: {$r['skipped']}"); return self::SUCCESS;
    }
}
