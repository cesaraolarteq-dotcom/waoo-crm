<?php
namespace Tests\Support;
final class QuickPaymentMail implements \App\Services\Mail\MailTransport
{
    public array $sent=[];public bool $fail=false;
    public function configured():bool{return true;}
    public function send(\App\Services\Mail\OutboundEnvelope $m):\App\Services\Mail\TransportReceipt{$this->sent[]=$m;if($this->fail)throw new \RuntimeException('Synthetic transport outcome unknown');return new \App\Services\Mail\TransportReceipt('synthetic','mail_'.$m->id);}
}
