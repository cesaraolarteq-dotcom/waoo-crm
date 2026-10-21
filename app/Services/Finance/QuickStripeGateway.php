<?php
namespace App\Services\Finance;
interface QuickStripeGateway extends StripeGateway
{
    /** Authenticated account evidence; never inferred from a configured key alone. */
    public function verifyMethods(array $methods):array;
}
