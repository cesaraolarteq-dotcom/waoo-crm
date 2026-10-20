<?php
namespace App\Services\Portal;

final readonly class RevealedPortalLink implements \JsonSerializable
{
    public function __construct(public int $grant_id, public int $event_id, public string $url, public string $expires_at, public ?string $short_url = null) {}
    public function jsonSerialize(): array
    {
        $result = get_object_vars($this);
        if ($this->short_url === null) unset($result['short_url']);
        return $result;
    }
}
