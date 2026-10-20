<?php
namespace Tests\Support;
use App\Services\Finance\StripeRefundGateway;
final class FakeStripeRefundGateway implements StripeRefundGateway
{
    public array $responses = []; public array $calls = []; public bool $fail = false;
    public array $charges = [];
    public function retrieveCharge(string $chargeId): array
    {
        $this->calls[] = $chargeId;
        if ($this->fail) throw new \RuntimeException('PRIVATE_PROVIDER_CHARGE_BODY');
        return $this->charges[$chargeId];
    }
    public function retrieveRefund(string $refundId): array
    {
        $this->calls[] = $refundId;
        if ($this->fail) throw new \RuntimeException('PRIVATE_PROVIDER_BODY Maria billing information');
        return $this->responses[$refundId];
    }
}
