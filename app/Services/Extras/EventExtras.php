<?php
namespace App\Services\Extras;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\Labels;
use App\Models\{Character, Contract, Event, EventChange, EventExtra, EventService, FinancialRevision, ImportedProjectLink, PriceVersion, ServiceVariant, User};
use App\Services\Audit;
use App\Services\Availability\AdmitEventCapacity;
use App\Services\Portal\PortalContext;
use App\Services\Records\CharacterRecipe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * EXTRAS-01 (owner 2026-11-02/03). From her link the client adds a catalog extra, or an additional character to the
 * package, up to 7 days before the party, when the whole team still fits. She reads the annex and ticks a box; the extra is
 * then part of the event: final, non-refundable, added to the balance (the deposit target does not change), and it runs at
 * the same time as the booked service (never extends the party). Nothing is charged here.
 */
final class EventExtras
{
    public const DAYS_BEFORE = 7;

    /** Why extras are not offered now, or null when they are. */
    public static function blocked(Event $e): ?string
    {
        if ($e->status !== 'capacity_reserved') return 'not_booked';
        if ($e->getAttribute('responsible_system') === 'honeybook' || ImportedProjectLink::where('event_id', $e->id)->exists()) return 'honeybook';
        if (CarbonImmutable::now()->addDays(self::DAYS_BEFORE)->greaterThan($e->starts_at)) return 'too_late';
        if (! Contract::where('event_id', $e->id)->where('state', 'signed')->exists()) return 'not_signed';
        if (EventChange::pendingSignature($e->id)) return 'change_pending';
        if (! FinancialRevision::where('event_id', $e->id)->exists()) return 'no_amounts';

        return null;
    }

    /** @return list<array{key:string,name:string,amount_cents:int,description:?string}> */
    public static function offers(Event $e): array
    {
        $minutes = (int) $e->starts_at->diffInMinutes($e->ends_at);
        $out = [];
        // Catalog extras that fit inside the party time (they run at the same time; an extra longer than the party is not offered).
        $have = $e->services()->pluck('service_variant_id')->all();
        foreach (ServiceVariant::where('offered_as_extra', true)->where('status', 'active')->orderBy('name')->get() as $v) {
            if (in_array($v->id, $have, true) || (int) $v->duration_minutes > $minutes || ! empty($v->character_options)) continue;
            $price = PriceVersion::where('service_variant_id', $v->id)->where('currency', 'USD')->orderByDesc('id')->first();
            if ($price) $out[] = ['key' => 'v'.$v->id, 'name' => $v->name, 'amount_cents' => (int) $price->amount_cents, 'description' => $v->long_description, 'requirements' => $v->requirements ?? []];
        }
        // An additional character on a package that has characters, at the catalog price for each extra character.
        foreach ($e->services()->with('characters')->get() as $s) {
            $v = ServiceVariant::find($s->service_variant_id);
            if (! $v || empty($v->character_options)) continue;
            $taken = $s->characters->pluck('character_id')->all();
            $options = collect($v->character_options)->keyBy('character_id');
            foreach ($options->keys()->diff($taken) as $cid) {
                $c = Character::whereKey($cid)->where('active', true)->first();
                if ($c) $out[] = ['key' => 'c'.$s->id.'-'.$c->id, 'name' => 'Personaje adicional: '.$c->name, 'amount_cents' => ServiceVariant::extraCharacterPrice($v), 'description' => null,
                    'requirements' => $options[$cid]['requirements'] ?? []];
            }
        }

        return $out;
    }

    /**
     * What the link shows: only the offers the whole team can still cover on that date (read-only preview, no hold), so the
     * client never reads an annex, ticks the box and then hears "it does not fit". accept() still re-checks under the locks.
     */
    public static function fitting(Event $e): array
    {
        return array_values(array_filter(self::offers($e), fn ($o) => AdmitEventCapacity::previewWith($e, $o['requirements']) === 'available'));
    }

    /** The annex exactly as the client reads and accepts it (approved text, 2026-11-02). */
    public static function annex(Event $e, array $offer, string $lang): string
    {
        $rev = FinancialRevision::where('event_id', $e->id)->orderByDesc('revision')->firstOrFail();
        $date = $e->starts_at->setTimezone('America/Chicago')->locale($lang)->isoFormat($lang === 'es' ? 'dddd D [de] MMMM [de] YYYY' : 'dddd, MMMM D, YYYY');
        $name = $lang === 'en' ? preg_replace('/^Personaje adicional: /', 'Additional character: ', $offer['name']) : $offer['name'];
        $amount = Labels::money($offer['amount_cents']); $total = Labels::money($rev->total_cents + $offer['amount_cents']);

        return $lang === 'en'
            ? "Contract addendum · Party on {$date}. You are adding: {$name} for {$amount}. The add-on runs at the same time as your service; it does not extend the party time. Once you accept, the add-on is part of your event and cannot be cancelled or refunded, because we reserve that service and no longer offer it to other clients. New total: {$total}. The amount is added to your balance, due 24 hours before the party. Everything else in your contract stays the same."
            : "Anexo al contrato · Fiesta del {$date}. Agregas: {$name} por {$amount}. El extra se realiza al mismo tiempo que tu servicio; no extiende la hora de la fiesta. Al aceptar, el extra queda agregado a tu evento y no se puede cancelar ni reembolsar, porque reservamos ese servicio y ya no se ofrece a otros clientes. Nuevo total: {$total}. El monto se suma a tu saldo, que vence 24 horas antes de la fiesta. Todo lo demás de tu contrato sigue igual.";
    }

    /** Accept one extra: capacity re-checked under the day locks, then service + new revision + evidence in one transaction. */
    public function accept(PortalContext $ctx, string $key, string $sha, string $lang): EventExtra
    {
        $lang = $lang === 'en' ? 'en' : 'es';

        return DB::transaction(function () use ($ctx, $key, $sha, $lang) {
            \App\Services\Scheduling\DailyCapacity::lock();
            $event = Event::whereKey($ctx->eventId)->lockForUpdate()->firstOrFail();
            $grant = $ctx->authorize($event->id, 'contract.sign', true);
            RecordWriteRejected::when(($why = self::blocked($event)) !== null, 409, self::message($why, $lang));
            $offer = collect(self::offers($event))->firstWhere('key', $key);
            RecordWriteRejected::unless($offer !== null, 409, $lang === 'es' ? 'Ese extra ya no está disponible. Recarga la página.' : 'That add-on is no longer available. Please reload.');
            $text = self::annex($event, $offer, $lang);
            RecordWriteRejected::unless(hash_equals(hash('sha256', $text), $sha), 409, $lang === 'es' ? 'El anexo cambió. Recarga la página y léelo de nuevo.' : 'The addendum changed. Please reload and read it again.');
            $prev = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->lockForUpdate()->firstOrFail();

            if ($key[0] === 'v') {
                $variant = ServiceVariant::whereKey((int) substr($key, 1))->lockForUpdate()->firstOrFail();
                $price = PriceVersion::where('service_variant_id', $variant->id)->where('currency', 'USD')->orderByDesc('id')->firstOrFail();
                $service = EventService::create(['event_id' => $event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id,
                    'requirements_snapshot' => $variant->requirements ?? [], 'duration_minutes' => min((int) $variant->duration_minutes, (int) $event->starts_at->diffInMinutes($event->ends_at)), 'operational_ready' => true]);
                $line = ['kind' => 'extra', 'event_service_id' => $service->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'name' => $variant->name,
                    'long_description' => $variant->long_description, 'duration_minutes' => $service->duration_minutes, 'requirements_snapshot' => $service->requirements_snapshot,
                    'characters' => [], 'quantity' => 1, 'amount_cents' => $offer['amount_cents']];
                $characterId = null;
            } else {
                [$serviceId, $characterId] = array_map('intval', explode('-', substr($key, 1)));
                $service = EventService::whereKey($serviceId)->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
                $variant = ServiceVariant::findOrFail($service->service_variant_id);
                $chars = $service->characters()->get()->map(fn ($r) => array_filter($r->only(['character_id', 'quantity', 'outfit', 'note']), fn ($v) => $v !== null))->all();
                $compiled = CharacterRecipe::compile($variant, [...$chars, ['character_id' => $characterId, 'quantity' => 1]], 'extras');
                // The package keeps its agreed line; the additional character is its own annex line.
                $service->forceFill(['requirements_snapshot' => $compiled['requirements']])->save();
                $service->characters()->delete(); $service->characters()->createMany($compiled['characters']);
                $line = ['kind' => 'extra_character', 'event_service_id' => $service->id, 'character_id' => $characterId, 'name' => $offer['name'], 'long_description' => null,
                    'duration_minutes' => 0, 'quantity' => 1, 'amount_cents' => $offer['amount_cents']];
            }
            // Whole team (people, costumes, equipment, drivers, 4 at once, 10 a day) with the extra included; anything less rolls back.
            $state = AdmitEventCapacity::state($event->fresh(), []);
            RecordWriteRejected::unless($state === 'available', 409, $lang === 'es'
                ? 'Ese extra ya no cabe en tu fecha. No se agregó nada. Escríbenos por WhatsApp y lo vemos.'
                : 'That add-on no longer fits your date. Nothing was added. Message us on WhatsApp and we will check.');

            $copy = collect($prev->getAttributes())->except(['id', 'revision', 'event_version', 'lines_snapshot', 'subtotal_cents', 'total_cents', 'created_at', 'updated_at'])->all();
            foreach (['discounts_snapshot'] as $json) if (array_key_exists($json, $copy) && is_string($copy[$json])) $copy[$json] = json_decode($copy[$json], true);
            $event->version++; $event->save();
            // Deposit target and balance due date stay as agreed; only the total (and so the balance) grows.
            $revision = FinancialRevision::create([...$copy, 'revision' => $prev->revision + 1, 'event_version' => $event->version,
                'lines_snapshot' => [...(array) $prev->lines_snapshot, $line], 'subtotal_cents' => $prev->subtotal_cents + $offer['amount_cents'],
                'total_cents' => $prev->total_cents + $offer['amount_cents'], 'created_by' => $prev->created_by]);
            \App\Services\Finance\SupersedeCollections::forEvent($event->id, 'financial_revision_changed');
            $extra = EventExtra::create(['event_id' => $event->id, 'event_service_id' => $service->id, 'financial_revision_id' => $revision->id, 'portal_grant_id' => $grant->id,
                'kind' => $key[0] === 'v' ? 'service' : 'character', 'service_variant_id' => $key[0] === 'v' ? $variant->id : null, 'price_version_id' => $line['price_version_id'] ?? null,
                'character_id' => $characterId, 'amount_cents' => $offer['amount_cents'], 'locale' => $lang, 'annex_text' => $text, 'annex_sha256' => hash('sha256', $text),
                'ip' => $ctx->ip, 'user_agent' => mb_substr($ctx->userAgent, 0, 500), 'accepted_at' => now()]);
            Audit::recordSystem('event.extra_added', $event, [], ['event_extra_id' => $extra->id, 'kind' => $extra->kind, 'amount_cents' => $extra->amount_cents,
                'financial_revision_id' => $revision->id, 'total_cents' => $revision->total_cents, 'portal_grant_id' => $grant->id]);

            return $extra;
        }, 3);
    }

    public static function message(?string $why, string $lang): string
    {
        $es = ['too_late' => 'Los extras se agregan hasta 7 días antes de la fiesta. Escríbenos por WhatsApp.', 'not_signed' => 'Primero firma tu contrato.',
            'change_pending' => 'Tienes un cambio de fecha por firmar. Fírmalo primero.'];
        $en = ['too_late' => 'Add-ons can be added up to 7 days before the party. Message us on WhatsApp.', 'not_signed' => 'Please sign your contract first.',
            'change_pending' => 'You have a date change to sign. Please sign it first.'];

        return ($lang === 'en' ? $en : $es)[$why] ?? ($lang === 'en' ? 'Add-ons are not available for this party. Message us on WhatsApp.' : 'Los extras no están disponibles para esta fiesta. Escríbenos por WhatsApp.');
    }
}
