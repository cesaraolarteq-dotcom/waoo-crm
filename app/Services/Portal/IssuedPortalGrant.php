<?php
namespace App\Services\Portal;
final readonly class IssuedPortalGrant implements \JsonSerializable
{
    public function __construct(public int $id, public string $url, public string $token, public string $expiresAt, public ?string $short_url = null) {}
    public function jsonSerialize(): array
    {
        $result = get_object_vars($this);
        if ($this->short_url === null) unset($result['short_url']);
        return $result;
    }
}
