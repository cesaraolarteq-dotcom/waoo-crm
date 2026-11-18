<?php
namespace App\Services\Finance;

/** Card refunds go back to the original Stripe payment; the idempotency key makes a retry return the same refund. */
interface StripeRefunds
{
    /** Creates the refund after proving the payment's account/mode. @return array{id:string,status:string,amount:int,payment_intent:string} */
    public function refund(string $paymentIntentId, int $amountCents, string $key): array;

    /** Current state of a refund already created for that payment and amount (pending ones settle later). @return array{id:string,status:string,amount:int,payment_intent:string} */
    public function retrieve(string $refundId, string $paymentIntentId, int $amountCents): array;
}
