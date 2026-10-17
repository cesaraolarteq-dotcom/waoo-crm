<?php
namespace App\Services\Mail;
final readonly class OutboundEnvelope
{
    public function __construct(public int $id,#[\SensitiveParameter] public string $recipient,public string $subject,public string $html,public string $text){}
}
