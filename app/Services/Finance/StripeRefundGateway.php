<?php
namespace App\Services\Finance;
/** Read existing provider state only. No refund initiation capability. */
interface StripeRefundGateway
{
    public function retrieveRefund(string $refundId): array;
    public function retrieveCharge(string $chargeId): array;
}
