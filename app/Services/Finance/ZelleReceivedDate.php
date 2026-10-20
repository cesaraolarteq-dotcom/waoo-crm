<?php
namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Receipt days are Houston calendar days; the request never contains a moving clock value. */
final class ZelleReceivedDate
{
    public static function rules(): array
    {
        return ['received_at' => ['required_without:received_on', 'prohibits:received_on', 'date', 'before_or_equal:now', new \App\Rules\ExplicitOffset],
            'received_on' => ['required_without:received_at', 'prohibits:received_at', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Chicago')->format('Y-m-d')]];
    }

    public static function timestamp(array $data): CarbonImmutable
    {
        if (isset($data['received_at'])) return CarbonImmutable::parse($data['received_at'])->utc()->startOfSecond();
        $now = CarbonImmutable::now('America/Chicago');
        $day = $data['received_on'];
        if ($day > $now->format('Y-m-d')) throw ValidationException::withMessages(['received_on'=>'La fecha no puede estar en el futuro.']);
        return ($day === $now->format('Y-m-d') ? $now : CarbonImmutable::parse($day, 'America/Chicago')->setTime(12, 0))->utc()->startOfSecond();
    }

    public static function bounds(array $data): array
    {
        $date = self::timestamp($data)->setTimezone('America/Chicago')->startOfDay();
        return [$date->utc(), $date->addDay()->utc()];
    }
}
