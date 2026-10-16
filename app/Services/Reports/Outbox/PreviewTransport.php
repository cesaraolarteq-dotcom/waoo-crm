<?php

namespace App\Services\Reports\Outbox;

interface PreviewTransport
{
    /** Return a durable local simulation receipt. Never send mail. */
    public function record(int $deliveryId, string $key, string $payloadJson): string;
}
