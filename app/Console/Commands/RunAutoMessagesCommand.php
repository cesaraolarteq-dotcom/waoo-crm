<?php
namespace App\Console\Commands;

use App\Services\Messages\RunAutoMessages;
use Illuminate\Console\Command;

class RunAutoMessagesCommand extends Command
{
    protected $signature = 'waoo:messages';
    protected $description = 'Send the automatic client messages that are switched on (Mensajes automáticos).';

    public function handle(RunAutoMessages $runner): int
    {
        $this->line(json_encode($runner->run()));
        return self::SUCCESS;
    }
}
