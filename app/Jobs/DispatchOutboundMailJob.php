<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class DispatchOutboundMailJob implements ShouldQueue
{
    use Queueable;
    public int $tries=1;
    public function __construct(public int $messageId){}
    public function handle(\App\Services\Mail\DispatchOutboundMail $service):void{$service->execute($this->messageId);}
}
