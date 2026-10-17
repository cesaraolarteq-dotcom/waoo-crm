<?php
namespace App\Console\Commands;
use App\Jobs\DispatchOutboundMailJob;
use App\Models\{OutboundMessage,OutboundMessageState};
use App\Services\Finance\Sandbox;
use Illuminate\Console\Command;
class DispatchDueMail extends Command
{
    protected $signature='waoo:mail-due {--limit=50}';protected $description='Queue bounded due sandbox message IDs; never activates a transport.';
    public function handle():int
    {
        Sandbox::require('sandbox_mail');$raw=(string)$this->option('limit');if(!ctype_digit($raw)||(int)$raw<1||(int)$raw>200){$this->error('Limit must be 1–200.');return self::FAILURE;}
        $ids=OutboundMessage::where('due_at','<=',now())->whereIn('id',OutboundMessageState::where('status','scheduled')->select('outbound_message_id'))->orderBy('id')->limit((int)$raw)->pluck('id');
        foreach($ids as $id)DispatchOutboundMailJob::dispatch((int)$id);$this->info('Queued message IDs: '.$ids->count());return self::SUCCESS;
    }
}
