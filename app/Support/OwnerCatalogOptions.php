<?php
namespace App\Support;

/** Adds only the approved duration/price to an existing owner-supplied recipe; never invents resources. */
final class OwnerCatalogOptions
{
    public static function packages(array $packages, array $options): array
    {
        $choice = $options['glitter_mobile'];
        // An explicit owner-supplied target takes precedence; avoid silently replacing later catalog choices.
        foreach ($packages as $package) if (($package['name'] ?? null) === $choice['name']) return $packages;
        foreach ($packages as $key => $package) {
            if (($package['name'] ?? null) !== $choice['source_name'] || (string) ($package['duration_min'] ?? '') !== (string) $choice['source_duration_minutes']) continue;
            $target = $key.'-owner-90min';
            if (array_key_exists($target, $packages)) throw new \LogicException('Owner catalog option key already exists.');
            $packages[$target] = array_replace($package, ['name' => $choice['name'], 'duration_min' => $choice['duration_minutes'], 'price' => $choice['amount_cents'] / 100]);
            break;
        }
        return $packages;
    }
}
