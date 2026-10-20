<?php

namespace App\Services\Integrity;

use App\Exceptions\RecordWriteRejected;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** Frozen persisted-field representations. Never extend V1 for a new column or ORM presentation change. */
final class EvidenceV1
{
    public const VERSION = 1;
    private const PROFILES = [
        'financial' => ['table' => 'financial_revisions',
            'int' => 'id event_id revision event_version subtotal_cents travel_fee_cents tax_cents customer_fee_cents total_cents deposit_target_cents created_by quote_revision_id discount_cents',
            'json' => 'lines_snapshot discounts_snapshot', 'text' => 'currency', 'date' => 'balance_due_at created_at updated_at'],
        'ledger' => ['table' => 'ledger_entries', 'int' => 'id event_id financial_revision_id amount_cents verified_by',
            'text' => 'environment kind method currency external_reference external_identity_key source_type source_id note', 'date' => 'received_at created_at updated_at'],
        'service' => ['table' => 'event_services', 'int' => 'id event_id service_variant_id price_version_id duration_minutes',
            'bool' => 'operational_ready', 'json' => 'requirements_snapshot', 'date' => 'created_at updated_at'],
        'character_selection' => ['table' => 'event_service_characters', 'int' => 'id event_service_id character_id quantity',
            'text' => 'character_name_snapshot outfit note', 'date' => 'created_at updated_at'],
        'reservation' => ['table' => 'resource_reservations', 'int' => 'id event_id resource_id', 'date' => 'starts_at ends_at created_at updated_at'],
        'booking' => ['table' => 'booking_evidence', 'int' => 'id event_id amount_cents verified_by imported_revision_id',
            'text' => 'method reference', 'json' => 'staff_assignment_snapshot', 'date' => 'received_at received_date created_at updated_at'],
        'operational_review' => ['table' => 'operational_reviews', 'int' => 'id event_id reviewed_by', 'json' => 'evidence', 'date' => 'created_at updated_at'],
        'resource' => ['table' => 'resources', 'int' => 'id version character_id', 'text' => 'status kind outfit', 'json' => 'skills'],
        'block' => ['table' => 'resource_blocks', 'int' => 'id resource_id', 'date' => 'starts_at ends_at'],
        'customer' => ['table' => 'customers', 'int' => 'id version', 'text' => 'name phone email locale'],
        'event' => ['table' => 'events', 'int' => 'id version customer_id setup_minutes breakdown_minutes',
            'text' => 'status responsible_system address postal_code', 'date' => 'starts_at ends_at'],
        'portal' => ['table' => 'portal_grants', 'int' => 'id event_id', 'json' => 'scopes', 'date' => 'updated_at revoked_at expires_at'],
    ];
    private const AUDIT = ['id', 'actor_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'created_at'];

    public static function model(Model $model, string $profile): array
    {
        $spec = self::PROFILES[$profile] ?? throw new \LogicException('Unknown V1 evidence profile.');
        self::require($model->getTable() === $spec['table']);
        $raw = $model->getRawOriginal(); $out = [];
        foreach ($spec as $type => $names) {
            if ($type === 'table') continue;
            foreach (explode(' ', $names) as $name) {
                self::require(array_key_exists($name, $raw));
                $out[$name] = self::value($raw[$name], $type);
            }
        }
        if ($profile === 'service') {
            self::require($model->relationLoaded('characters'));
            $out['characters'] = self::many($model->getRelation('characters'), 'character_selection');
        }
        return $out;
    }

    /** Exact legacy native TIP raw-financial component, with a frozen list, not an ORM/cast serialization. */
    public static function financialRaw(Model $model): array
    {
        self::require($model->getTable() === 'financial_revisions');
        $raw = $model->getRawOriginal(); $out = [];
        foreach (self::PROFILES['financial'] as $type => $names) {
            if ($type === 'table') continue;
            foreach (explode(' ', $names) as $name) {
                self::require(array_key_exists($name, $raw)); $out[$name] = $raw[$name];
            }
        }
        return $out;
    }

    public static function audit(object $row): array
    {
        $raw = (array)$row; $out = [];
        foreach (self::AUDIT as $name) { self::require(array_key_exists($name, $raw)); $out[$name] = $raw[$name]; }
        return $out;
    }

    public static function many(iterable $models, string $profile): array
    {
        $out = []; foreach ($models as $model) $out[] = self::model($model, $profile); return $out;
    }

    public static function operations(iterable $services, iterable $reservations, iterable $booking, iterable $resources): array
    {
        return ['services' => self::many($services, 'service'), 'reservations' => self::many($reservations, 'reservation'),
            'booking' => self::many($booking, 'booking'), 'resources' => self::many($resources, 'resource')];
    }

    private static function value(mixed $value, string $type): mixed
    {
        if ($value === null) return null;
        try {
            return match ($type) {
                'int' => self::integer($value),
                'bool' => match ($value) { 0, '0', false => false, 1, '1', true => true, default => throw new \UnexpectedValueException },
                'text' => is_string($value) ? $value : throw new \UnexpectedValueException,
                'json' => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
                'date' => self::date($value),
                default => throw new \LogicException('Unknown V1 evidence type.'),
            };
        } catch (\Throwable) { throw new RecordWriteRejected(409, 'El formato de la evidencia requiere revisión.'); }
    }

    private static function integer(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) && filter_var($value, FILTER_VALIDATE_INT) !== false) return (int)$value;
        throw new \UnexpectedValueException;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?)?$/D', $value)) throw new \UnexpectedValueException;
        $date = CarbonImmutable::parse($value, 'UTC');
        if ($date->format(strlen($value) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d') !== substr($value, 0, 19)) throw new \UnexpectedValueException;
        return $date->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function require(bool $condition): void
    {
        RecordWriteRejected::unless($condition, 409, 'La evidencia requiere una lectura completa y compatible.');
    }
}
