<?php
namespace App\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ResponsibilityRejected extends ConflictHttpException
{
    public const CODE = 'responsible_system_honeybook';

    public function __construct()
    {
        parent::__construct('HoneyBook cobra y avisa este evento. Requiere un cambio conciliado al CRM.');
    }

    public function report(): bool { return true; }

    public function render(Request $request)
    {
        return $request->expectsJson()
            ? response()->json(['code' => self::CODE, 'message' => $this->getMessage()], 409)
            : response($this->getMessage(), 409);
    }
}
