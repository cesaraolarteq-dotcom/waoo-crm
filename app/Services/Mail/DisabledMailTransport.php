<?php
namespace App\Services\Mail;
final class DisabledMailTransport implements MailTransport
{
    public function configured():bool{return false;}
    public function send(#[\SensitiveParameter] OutboundEnvelope $message):TransportReceipt{throw new \RuntimeException('Mail transport is not configured.');}
}
