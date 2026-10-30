<?php
namespace App\Services\Finance;

/** Card refunds go back to the original Stripe payment; the idempotency key makes a retry return the same refund. */
interface StripeRefunds
{
    /** @return array{id:string,status:string,amount:int,payment_intent:string} */
    public function refund(string $paymentIntentId, int $amountCents, string $key): array;
}
