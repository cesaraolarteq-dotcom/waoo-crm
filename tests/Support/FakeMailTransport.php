<?php
namespace Tests\Support;
use App\Services\Mail\{MailTransport,OutboundEnvelope,TransportReceipt};
class FakeMailTransport implements MailTransport
{
    public array $sent=[];public bool $uncertain=false;public ?string $counterPath=null;
    public function configured():bool{return true;}
    public function send(#[\SensitiveParameter] OutboundEnvelope $message):TransportReceipt
    {
        $this->sent[]=$message->id;if($this->counterPath!==null)file_put_contents($this->counterPath,$message->id."\n",FILE_APPEND|LOCK_EX);if($this->uncertain)throw new \RuntimeException('Synthetic provider uncertainty; message text must not escape.');return new TransportReceipt('fake-only','fake-'.$message->id);
    }
}
