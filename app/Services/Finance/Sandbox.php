<?php
namespace App\Services\Finance;

use Illuminate\Auth\Access\AuthorizationException;

final class Sandbox
{
    public static function require(string $feature = 'sandbox_finance'): void
    {
        if (! app()->environment(['local', 'testing', 'staging']) || ! config('waoo.'.$feature, false)) {
            throw new AuthorizationException('Esta función solo está habilitada en el entorno de pruebas autorizado.');
        }
    }
}
