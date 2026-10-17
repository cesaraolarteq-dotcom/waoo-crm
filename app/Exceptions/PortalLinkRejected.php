<?php
namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

final class PortalLinkRejected extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
    // Handled: never log a wrapped crypto/SQL exception with its secret-bearing arguments.
    public function report(): bool { return true; }
    public function render(Request $request)
    {
        return response()->json(['code' => $this->errorCode, 'message' => $this->getMessage()], $this->status);
    }
    public static function unrecoverable(): self
    {
        return new self(409, 'link_not_recoverable', 'No se puede recuperar este enlace. Crea uno nuevo.');
    }
    public static function unavailable(): self
    {
        return new self(503, 'link_reveal_unavailable', 'No se pudo recuperar el enlace. Inténtalo de nuevo.');
    }
}
