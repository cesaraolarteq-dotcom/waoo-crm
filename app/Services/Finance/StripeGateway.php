<?php
namespace App\Services\Finance;
interface StripeGateway
{
    public function createCheckout(array $parameters, string $key): array;
    public function retrieveCheckout(string $id): array;
    public function expireCheckout(string $id): void;
}
