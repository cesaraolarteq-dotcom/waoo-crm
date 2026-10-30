<?php
namespace App\Services\Campaigns;

use App\Exceptions\RecordWriteRejected;
use App\Models\{AnniversaryCode, Customer, Event, FinancialRevision, LedgerEntry, PriceVersion, User};
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ANNIV-01 "Un año después" (owner spec docs/plans/ANNIVERSARY-REBOOK.md, design 2026-10-29):
 * - one unique code per client and anchor (the client's latest birthday party that happened), issued 46 weeks after it,
 *   only when the client has not booked again and has an email; skipped clients are recorded with the reason;
 * - 10 % off the regular package price only (no extra characters, never the travel fee), single use, 7 days, only on a
 *   new booking of that same client created after the code, never with another discount (an accepted quote);
 * - issuing never sends anything: delivery is a separate step behind the mail gate (owner hold: no email yet).
 */
final class AnniversaryCampaign
{
    public const SEND_AFTER_DAYS = 322; // 46 weeks after the party, 6 weeks before the anniversary
    public const VALID_DAYS = 7;
    public const PERCENT = 10;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I

    /** Issues today's codes. Idempotent: a client and anchor get one row ever. @return array{issued:int, skipped:int} */
    public function issue(CarbonImmutable $asOf): array
    {
        $today = $asOf->setTimezone('America/Chicago')->startOfDay();
        return DB::transaction(function () use ($today) {
            [$anchors, $later] = ReadBirthdayCampaignDraft::rows();
            $latest = [];
            foreach ($anchors as $a) {
                if ($a['status'] !== 'completed' || $a['event_type'] !== 'birthday' || ! is_string($a['date']) || $a['customer_id'] === null) continue;
                if (! \DateTimeImmutable::createFromFormat('!Y-m-d', $a['date']) || $a['date'] >= $today->toDateString()) continue;
                if (! isset($latest[$a['customer_id']]) || $a['date'] > $latest[$a['customer_id']]['date']) $latest[$a['customer_id']] = $a;
            }
            $issued = $skipped = 0;
            foreach ($latest as $customerId => $a) {
                $anchor = CarbonImmutable::createFromFormat('!Y-m-d', $a['date'], 'America/Chicago');
                // Only inside the window: from 46 weeks after the party until its anniversary.
                if ($today < $anchor->addDays(self::SEND_AFTER_DAYS) || $today >= $anchor->addYear()) continue;
                if (AnniversaryCode::where('customer_id', $customerId)->where('anchor_key', $a['key'])->exists()) continue;
                $reason = null;
                foreach ($later as $e) {
                    if ($e['customer_id'] === $customerId && $e['key'] !== $a['key'] && is_string($e['date']) && $e['date'] > $a['date'] && $e['status'] !== 'cancelled') { $reason = 'already_rebooked'; break; }
                }
                if ($reason === null && blank(Customer::whereKey($customerId)->value('email'))) $reason = 'no_email';
                $base = ['customer_id' => $customerId, 'anchor_key' => $a['key'], 'anchor_date' => $a['date'], 'issued_at' => now()];
                if ($reason !== null) {
                    AnniversaryCode::create([...$base, 'state' => 'skipped', 'skip_reason' => $reason]); $skipped++; continue;
                }
                $row = AnniversaryCode::create([...$base, 'state' => 'ready', 'code' => self::uniqueCode(),
                    'expires_at' => $today->addDays(self::VALID_DAYS)->endOfDay()->utc()]);
                Audit::recordSystem('anniversary.code_issued', $row, [], ['customer_id' => $customerId, 'anchor_key' => $a['key']]);
                $issued++;
            }
            return ['issued' => $issued, 'skipped' => $skipped];
        }, 3);
    }

    public static function normalize(?string $code): ?string
    {
        $c = strtoupper(preg_replace('/\s+/', '', (string) $code));
        return $c === '' ? null : $c;
    }

    /** Read-only check for the booking screen (the booking itself re-checks under a lock). @return array{ok:bool,message:string} */
    public static function check(?string $code, ?int $customerId): array
    {
        $row = AnniversaryCode::where('code', self::normalize($code))->first();
        $no = fn (string $m) => ['ok' => false, 'message' => $m];
        if (! $row) return $no('Ese código no existe. Revisa que esté bien escrito.');
        if ($row->state === 'redeemed') return $no('Ese código ya se usó en otra reserva.');
        if (! in_array($row->state, ['ready', 'sent'], true)) return $no('Ese código no está activo.');
        if ($customerId === null) return $no('Elige primero al cliente que recibió el código.');
        if ($row->customer_id !== $customerId) return $no('Ese código es de otro cliente. Solo lo puede usar quien lo recibió.');
        if (now()->greaterThan($row->expires_at)) return $no('Ese código venció el '.$row->expires_at->setTimezone('America/Chicago')->translatedFormat('j \d\e F').'.');
        return ['ok' => true, 'message' => 'Código válido: 10 % menos en el paquete (el transporte se cobra completo). Vence el '.$row->expires_at->setTimezone('America/Chicago')->translatedFormat('j \d\e F').'.'];
    }

    /**
     * Redeems a code on a new booking of its own client. Call inside the booking transaction, after the event draft exists
     * and before its financial revision (which then carries the discount). Errors are on the 'promo_code' field.
     */
    public static function redeem(User $actor, string $code, Event $event): AnniversaryCode
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['promo_code' => $m]);
        $row = AnniversaryCode::where('code', self::normalize($code))->lockForUpdate()->first();
        if (! $row) $fail('Ese código no existe. Revisa que esté bien escrito.');
        if ($row->state === 'redeemed') $fail('Ese código ya se usó en otra reserva.');
        if (! in_array($row->state, ['ready', 'sent'], true)) $fail('Ese código no está activo.');
        if ($row->customer_id !== $event->customer_id) $fail('Ese código es de otro cliente. Solo lo puede usar quien lo recibió.');
        if (now()->greaterThan($row->expires_at)) $fail('Ese código venció el '.$row->expires_at->setTimezone('America/Chicago')->translatedFormat('j \d\e F').'.');
        if ($event->status !== 'draft' || $event->created_at->lessThan($row->issued_at)) $fail('El código solo aplica a una reserva nueva, hecha después de recibirlo.');
        if (\App\Models\Quote::where('event_id', $event->id)->exists()) $fail('El código no se combina con una cotización ni con otro descuento.');
        RecordWriteRejected::unless(! AnniversaryCode::where('redeemed_event_id', $event->id)->exists(), 409, 'Esta reserva ya tiene un código.');
        $row->fill(['state' => 'redeemed', 'redeemed_event_id' => $event->id, 'redeemed_at' => now(), 'redeemed_by' => $actor->id])->save();
        Audit::recordFor($actor, 'anniversary.code_redeemed', $row, [], ['event_id' => $event->id]);
        return $row;
    }

    /**
     * The discount a financial revision of this event carries: 10 % of the regular package prices (price versions only, so
     * extra characters and the travel fee are charged in full). Null when the event redeemed no code.
     * @param iterable<\App\Models\EventService> $services
     */
    public static function discount(Event $event, iterable $services): ?array
    {
        $row = AnniversaryCode::where('redeemed_event_id', $event->id)->first();
        if (! $row) return null;
        $base = 0;
        foreach ($services as $s) $base += (int) PriceVersion::whereKey($s->price_version_id)->value('amount_cents');
        $cents = intdiv($base * self::PERCENT + 50, 100); // rounded to the cent
        return ['cents' => $cents, 'snapshot' => [['kind' => 'anniversary', 'code' => $row->code, 'percent' => self::PERCENT,
            'applies_to' => 'package_price', 'base_cents' => $base, 'amount_cents' => $cents, 'anniversary_code_id' => $row->id]]];
    }

    /** Effectiveness for Reportes: issued (ready or sent), booked with the code, money and discount. */
    public static function stats(): array
    {
        $codes = AnniversaryCode::query()->whereIn('state', ['ready', 'sent', 'redeemed'])->get();
        $redeemed = $codes->where('state', 'redeemed');
        $revenue = $discount = 0;
        foreach ($redeemed as $r) {
            $rev = FinancialRevision::where('event_id', $r->redeemed_event_id)->orderByDesc('revision')->first();
            $revenue += (int) ($rev?->total_cents ?? 0); $discount += (int) ($rev?->discount_cents ?? 0);
        }
        $cancelled = Event::whereIn('id', $redeemed->pluck('redeemed_event_id'))->where('status', 'cancelled')->count();
        return ['issued' => $codes->count(), 'sent' => $codes->whereNotNull('sent_at')->count(), 'redeemed' => $redeemed->count(),
            'rate' => $codes->count() ? round(100 * $redeemed->count() / $codes->count(), 1) : null,
            'revenue_cents' => $revenue, 'discount_cents' => $discount, 'cancelled' => $cancelled,
            'expired' => $codes->where('state', '!=', 'redeemed')->filter(fn ($c) => $c->expires_at && $c->expires_at->isPast())->count(),
            'skipped' => AnniversaryCode::where('state', 'skipped')->selectRaw('skip_reason, count(*) c')->groupBy('skip_reason')->pluck('c', 'skip_reason')->all()];
    }

    private static function uniqueCode(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $c = 'WAOO-';
            for ($k = 0; $k < 5; $k++) $c .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            if (! AnniversaryCode::where('code', $c)->exists()) return $c;
        }
        throw new \RuntimeException('No se pudo generar un código único.');
    }
}
