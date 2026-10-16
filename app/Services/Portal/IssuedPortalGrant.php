<?php
namespace App\Services\Portal;
final readonly class IssuedPortalGrant
{
    public function __construct(public int $id, public string $url, public string $token, public string $expiresAt) {}
}
