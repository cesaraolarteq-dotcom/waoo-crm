<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CutoverCohortChanged extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('El grupo de HoneyBook cambió. Vuelve a revisar desde la primera página.');
    }

    public function report(): bool { return true; }

    public function render(Request $request)
    {
        return response()->json(['code' => 'cutover_cohort_changed', 'message' => $this->getMessage()], 409)
            ->header('Cache-Control', 'no-store, private');
    }
}
