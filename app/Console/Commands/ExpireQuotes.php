<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExpireQuotes extends Command
{
    protected $signature = 'waoo:expire-quotes {--limit=100 : Maximum rows per run (1–500)}';
    protected $description = 'Expire overdue sandbox quotes without touching accepted revisions, money or capacity';
    public function handle(\App\Services\Quotes\ExpireQuotes $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) { $this->error('limit must be an integer from1 to500.'); return self::FAILURE; }
        $this->info('Expired: '.$service->execute($limit)); return self::SUCCESS;
    }
}
