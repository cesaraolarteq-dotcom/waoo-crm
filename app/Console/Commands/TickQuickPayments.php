<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
final class TickQuickPayments extends Command
{
    protected $signature='waoo:quick-payments:tick {--limit=100}';
    protected $description='Process sandbox quick booking deadlines without a queue worker';
    public function handle(\App\Services\Booking\TickQuickPayments $service):int
    {
        if(!\App\Services\Booking\QuickPaymentProtocol::enabled()){$this->line('disabled');return self::SUCCESS;}
        $limit=filter_var($this->option('limit'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
        if($limit===false){$this->error('limit: 1–1000');return self::FAILURE;}
        $this->line(json_encode($service->execute($limit),JSON_THROW_ON_ERROR));return self::SUCCESS;
    }
}
