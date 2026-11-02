<?php
namespace App\Services\Finance;
interface QuickStripeGateway extends StripeGateway
{
    /** Authenticated account evidence; never inferred from a configured key alone. */
    public function verifyMethods(array $methods):array;
    /**
     * Lost-response recovery: the checkout this account created for one of our requests (client_reference_id) inside the given
     * creation window, or null when none exists there. Read only; never creates or changes a session.
     */
    public function findCheckout(string $clientReferenceId, int $createdFrom, int $createdTo):?array;
}
