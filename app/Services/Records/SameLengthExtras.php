<?php
namespace App\Services\Records;

use App\Exceptions\RecordWriteRejected;
use App\Models\{PriceVersion, ServiceVariant};

/** Owner's same-length extra policy. Validate catalog choices; never create a price or silently reprice a request. */
final class SameLengthExtras
{
    public const FAMILIES = ['Recreadora extra'];

    public static function applies(ServiceVariant $variant): bool
    {
        return preg_match('/^recreadora extra(?:$|\s|[·(])/iu', trim($variant->name)) === 1;
    }

    /** Inside the caller's admission transaction, after idempotent replay and before customer/event/money writes. */
    public static function assertForQuick(array $services, ?int $packagePriceId): void
    {
        RecordInput::validate(['services' => $services], ['services' => 'required|array|max:10', 'services.*' => 'array',
            'services.*.price_version_id' => 'required|integer|min:1|exists:price_versions,id']);
        $prices = PriceVersion::whereIn('id', array_column($services, 'price_version_id'))->get()->keyBy('id');
        $variants = ServiceVariant::whereIn('id', $prices->pluck('service_variant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $extras = $prices->filter(fn ($p) => isset($variants[$p->service_variant_id]) && self::applies($variants[$p->service_variant_id]));
        if ($extras->isEmpty()) return;
        $extensions=$prices->filter(fn($p)=>$variants[$p->service_variant_id]->extends_event);
        if ($packagePriceId === null) {
            $bases = $prices->except([...$extras->keys()->all(),...$extensions->keys()->all()]);
            if ($bases->count() === 1) $packagePriceId = (int) $bases->first()->id;
        }
        RecordWriteRejected::unless($packagePriceId !== null && $prices->has($packagePriceId) && !$extras->has($packagePriceId) && !$extensions->has($packagePriceId), 422,
            'Elige el paquete principal para comprobar la duración de la recreadora extra.');
        $package = $variants[$prices[$packagePriceId]->service_variant_id];
        RecordWriteRejected::unless($package->duration_minutes !== null && $package->duration_minutes > 0, 422,
            'Completa la duración del paquete en el catálogo antes de agregar recreadora extra.');
        $minutes=$package->duration_minutes+(int)$extensions->sum(fn($p)=>$variants[$p->service_variant_id]->duration_minutes);
        foreach ($extras as $price) RecordWriteRejected::unless($variants[$price->service_variant_id]->duration_minutes === $minutes, 422,
            'La recreadora extra debe cubrir el mismo tiempo del paquete. Elige la versión de '.$minutes.' minutos en el catálogo; no se cambió ningún precio ni se guardó la reserva.');
    }
}
