<?php
namespace App\Services\Mail;
final readonly class TransportReceipt
{
    public function __construct(public string $provider,public string $messageId){}
}
