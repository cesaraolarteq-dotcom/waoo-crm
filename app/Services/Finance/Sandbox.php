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
    public static function origin(): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (!$parts || empty($parts['host']) || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['scheme'] ?? null) !== 'https' && !(app()->environment(['local','testing']) && ($parts['scheme'] ?? null) === 'http' && in_array($parts['host'], ['localhost','127.0.0.1'], true)))) {
            throw new AuthorizationException('Configura un origen seguro para los enlaces del entorno de pruebas.');
        }
        return $origin;
    }

}
