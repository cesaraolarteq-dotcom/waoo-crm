<?php
declare(strict_types=1);

namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, QuickStripeCheckoutRequest, QuickStripeLease, QuickStripeLeaseEvent};
use App\Services\Finance\{MutationKey, Sandbox};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable request preparation only. Never invokes Stripe or claims a supported method/payment. */
final class QuickStripeCheckoutRequests
{
    private const KEYS = ['schema_version', 'request_id', 'lease_id', 'lease_version', 'lease_sha256',
        'event_id', 'event_version', 'customer_id', 'financial_revision_id', 'terms_sha256', 'account',
        'origin', 'requested_methods', 'created_at', 'provider_key', 'parameters'];

    public static function policy(): array
    {
        $account = config('waoo.stripe_test_account');
        $origin = Sandbox::origin();
        $methods = config('waoo.quick_stripe_requested_methods', ['card']);
        RecordWriteRejected::unless(is_string($account) && preg_match('/\Aacct_[A-Za-z0-9]+\z/', $account) === 1,
            409, 'Configura la cuenta Stripe de pruebas antes de preparar la solicitud.');
        self::safeOrigin($origin);
        RecordWriteRejected::unless(is_array($methods) && array_is_list($methods) && $methods !== []
            && count(array_filter($methods, 'is_string')) === count($methods)
            && count($methods) === count(array_unique($methods, SORT_REGULAR))
            && array_diff($methods, ['card', 'klarna', 'afterpay_clearpay']) === [],
            422, 'Revisa los métodos solicitados. No se habilita un método de Stripe sin comprobar su compatibilidad.');
        sort($methods, SORT_STRING);
        return ['account' => $account, 'origin' => $origin, 'requested_methods' => $methods];
    }

    private static function safeOrigin(string $origin): void
    {
        $parts = parse_url($origin);
        $local = app()->environment(['local', 'testing']) && ($parts['scheme'] ?? null) === 'http'
            && in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true);
        RecordWriteRejected::unless(filter_var($origin, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && !preg_match('/[\x00-\x20\x7f]/', $origin) && ($parts['path'] ?? '') === ''
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && (($parts['scheme'] ?? null) === 'https' || $local), 409, 'Revisa el origen seguro del portal.');
    }

    public static function parameters(string $id, int $eventId, array $terms, string $partyDate, string $origin, array $methods, int $expiresAt): array
    {
        $metadata = ['quick_booking_request_id' => $id, 'purpose' => 'quick_stripe_booking',
            'event_id' => (string) $eventId, 'terms_sha256' => $terms['sha256'],
            'principal_cents' => (string) $terms['principal_cents'], 'customer_fee_cents' => (string) $terms['customer_fee_cents']];
        $lines = [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $terms['principal_cents'],
            'product_data' => ['name' => 'Waoo Party · WP-'.$eventId,
                'description' => $partyDate.' · '.$terms['deposit_percent'].'% · Party services / Servicios de fiesta']]]];
        if ($terms['customer_fee_cents'] > 0) {
            $lines[] = ['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $terms['customer_fee_cents'],
                'product_data' => ['name' => 'Payment fee / Cargo por pago (3%)']]];
        }
        return ['mode' => 'payment', 'payment_method_types' => $methods, 'client_reference_id' => $id,
            'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata], 'line_items' => $lines,
            'expires_at' => $expiresAt, 'success_url' => $origin.'/portal?quick_booking_return='.$id,
            'cancel_url' => $origin.'/portal?quick_booking_return='.$id.'&cancelled=1'];
    }

    /** Validate against the original lease history, not a recomputed attacker-supplied request hash alone. */
    public static function verify(QuickStripeCheckoutRequest $request, QuickStripeLease $lease): array
    {
        try {
            $p = $request->envelope;
            RecordWriteRejected::unless(is_array($p) && count($p) === count(self::KEYS) && array_diff(self::KEYS, array_keys($p)) === []
                && $p['schema_version'] === 1 && is_string($p['request_id']) && Str::isUuid($p['request_id'])
                && $p['request_id'] === $request->id && $p['lease_id'] === $lease->id
                && $request->quick_stripe_lease_id === $lease->id && $p['event_id'] === $request->event_id
                && $p['event_id'] === $lease->event_id && $p['customer_id'] === $request->customer_id
                && $p['customer_id'] === $lease->customer_id && $p['financial_revision_id'] === $request->financial_revision_id
                && $p['financial_revision_id'] === $lease->financial_revision_id
                && is_int($p['lease_version']) && $p['lease_version'] > 0 && is_int($p['event_version']) && $p['event_version'] > 0
                && hash_equals($request->envelope_sha256, MutationKey::hash($p)), 409, 'La solicitud de Stripe requiere revisión.');
            $audit = DB::table('audit_entries')->where('action', 'quick_stripe_request.prepared')
                ->where('subject_type', $request->getTable())->where('subject_id', $request->id)->sharedLock()->sole();
            $audited = json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR);
            RecordWriteRejected::unless($audit->actor_id !== null && $audited === ['event_id' => $request->event_id,
                'lease_id' => $lease->id, 'lease_version' => $p['lease_version'], 'sha256' => $request->envelope_sha256],
                409, 'La huella de la solicitud no coincide con su auditoría original.');
            $history = QuickStripeLeaseEvent::where('quick_stripe_lease_id', $lease->id)->where('version', $p['lease_version'])->sharedLock()->firstOrFail();
            $source = $history->payload;
            RecordWriteRejected::unless(is_array($source) && $source['state'] === 'active'
                && hash_equals($p['lease_sha256'], $history->payload_sha256)
                && hash_equals($history->payload_sha256, MutationKey::hash($source))
                && $source['lease_id'] === $lease->id && $source['event_id'] === $p['event_id']
                && $source['event_version'] === $p['event_version'] && $source['customer_id'] === $p['customer_id']
                && $source['financial_revision_id'] === $p['financial_revision_id'] && $source['version'] === $p['lease_version'],
                409, 'La solicitud no coincide con el apartado original.');
            $terms = QuickStripeTerms::restore($source['terms']);
            $money = $terms->snapshot();
            $revision = FinancialRevision::findOrFail($p['financial_revision_id']);
            RecordWriteRejected::unless($p['terms_sha256'] === $money['sha256'] && $revision->event_id === $p['event_id']
                && QuickStripeLeases::financialHash($revision) === $source['financial_sha256'] && $revision->total_cents === $money['total_cents']
                && is_int($p['created_at']) && $p['created_at'] === $request->created_at->timestamp
                && is_string($p['account']) && preg_match('/\Aacct_[A-Za-z0-9]+\z/', $p['account']) === 1
                && is_string($p['origin']) && is_array($p['requested_methods']) && array_is_list($p['requested_methods'])
                && count(array_filter($p['requested_methods'], 'is_string')) === count($p['requested_methods'])
                && $p['requested_methods'] !== [] && count($p['requested_methods']) === count(array_unique($p['requested_methods'], SORT_REGULAR))
                && array_diff($p['requested_methods'], ['card', 'klarna', 'afterpay_clearpay']) === []
                && $p['provider_key'] === 'waoo-quick-test:'.$request->id, 409, 'La solicitud no coincide con sus términos originales.');
            self::safeOrigin($p['origin']);
            $sorted = $p['requested_methods']; sort($sorted, SORT_STRING);
            $expiry = $terms->checkoutExpiresAt(CarbonImmutable::createFromTimestampUTC($p['created_at']));
            $date = CarbonImmutable::parse($source['window']['party_start'])->setTimezone('America/Chicago')->format('Y-m-d');
            RecordWriteRejected::unless($sorted === $p['requested_methods'] && $p['parameters'] === self::parameters(
                $request->id, $p['event_id'], $money, $date, $p['origin'], $sorted, $expiry),
                409, 'Los parámetros guardados de Stripe cambiaron.');
            return ['envelope' => $p, 'terms' => $money];
        } catch (RecordWriteRejected $e) { throw $e;
        } catch (\Illuminate\Database\QueryException $e) { throw $e;
        } catch (\Throwable) { throw new RecordWriteRejected(409, 'La evidencia de la solicitud de Stripe requiere revisión.'); }
    }

    public static function current(Event $event, QuickStripeLease $lease, array $input): array
    {
        // Preserve C1's normal responsibility denial before S2's generic corruption boundary.
        \App\Services\Responsibility\RequireCrmResponsibility::check($event);
        $p = QuickStripeLeases::verify($lease, $event, false);
        QuickStripeLeases::lockInventory($p);
        $p = QuickStripeLeases::verify($lease, $event);
        RecordWriteRejected::unless($lease->state === 'active' && $lease->expires_at->isFuture() && $event->starts_at->isFuture()
            && $event->version === $input['expected_event_version'] && $lease->version === $input['expected_lease_version']
            && hash_equals($p['terms']['sha256'], $input['expected_terms_sha256']), 409, 'El apartado cambió o venció; revisa la fiesta.');
        RecordWriteRejected::unless(!DB::table('payment_exceptions')->where('event_id', $event->id)->where('state', 'open')->exists(),
            409, 'Resuelve las incidencias de pago antes de preparar otro cobro.');
        return $p;
    }

    public static function view(QuickStripeCheckoutRequest $request, QuickStripeLease $lease): array
    {
        ['envelope' => $p, 'terms' => $terms] = self::verify($request, $lease);
        return ['request_id' => $request->id, 'event_id' => $p['event_id'], 'event_version' => $p['event_version'],
            'lease_id' => $p['lease_id'], 'lease_version' => $p['lease_version'], 'financial_revision_id' => $p['financial_revision_id'],
            'request_sha256' => $request->envelope_sha256, 'principal_cents' => $terms['principal_cents'],
            'customer_fee_cents' => $terms['customer_fee_cents'], 'payable_cents' => $terms['payable_cents'], 'currency' => 'USD',
            'requested_methods' => $p['requested_methods'], 'created_at' => CarbonImmutable::createFromTimestampUTC($p['created_at'])->toIso8601String(),
            'checkout_expires_at' => CarbonImmutable::createFromTimestampUTC($p['parameters']['expires_at'])->toIso8601String(),
            'lease_expires_at' => CarbonImmutable::createFromTimestampUTC($terms['expires_at'])->toIso8601String(),
            'state' => 'prepared_only', 'provider_called' => false, 'method_support_verified' => false, 'payment_received' => false];
    }
}
