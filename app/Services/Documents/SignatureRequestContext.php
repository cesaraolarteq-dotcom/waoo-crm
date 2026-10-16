<?php
namespace App\Services\Documents;
use Illuminate\Http\Request;

final readonly class SignatureRequestContext
{
    private function __construct(public ?string $ip, public string $userAgent, public bool $adminAuthenticated) {}
    public static function fromRequest(Request $request): self
    {
        return new self($request->ip(), mb_substr((string) $request->userAgent(), 0, 2000), $request->user() !== null);
    }
}
