<?php
namespace App\Services\Portal;

final readonly class RevealedPortalLink
{
    public function __construct(public int $grant_id, public int $event_id, public string $url, public string $expires_at) {}
}
