<?php
namespace App\Services\Mail;
interface MailTransport
{
    public function configured():bool;
    public function send(#[\SensitiveParameter] OutboundEnvelope $message):TransportReceipt;
}
