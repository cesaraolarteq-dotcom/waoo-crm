<?php
namespace App\Services\History;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ExternalCustomerLink, HistoricalEvent, HistoricalEventRevision, HistoryImportDecision};
use App\Services\Finance\MutationKey;

final class HistoricalIntegrity
{
    public static function revision(HistoricalEvent $event, HistoricalEventRevision $revision): array
    {
        return self::protect(function () use ($event, $revision) {
            $p = $revision->payload;
            self::check(is_array($p) && hash_equals($revision->payload_sha256, MutationKey::hash($p))
                && ($p['history_id'] ?? null) === $event->id && $revision->historical_event_id === $event->id
                && ($p['customer_id'] ?? null) === $event->customer_id && $revision->customer_id === $event->customer_id
                && ($p['version'] ?? null) === $revision->version && $event->version === $revision->version
                && $event->current_revision_id === $revision->id && ($p['created_by'] ?? null) === $revision->created_by
                && $event->project_key === hash('sha256', $p['source']['project_id'])
                && $revision->source_sha256 === MutationKey::hash($p['source'])
                && $revision->facts_sha256 === MutationKey::hash($p['facts']));
            return $p;
        });
    }
    public static function contact(ExternalCustomerLink $link): array
    {
        return self::protect(function () use ($link) {
            $p = $link->payload;
            self::check(is_array($p) && $link->source_system === 'honeybook' && $link->contact_key === hash('sha256', $p['contact_id'])
                && ($p['customer_id'] ?? null) === $link->customer_id && ($p['version'] ?? null) === $link->version
                && ($p['created_by'] ?? null) === $link->created_by && hash_equals($link->payload_sha256, MutationKey::hash($p)));
            return $p;
        });
    }
    public static function decision(HistoryImportDecision $d): array
    {
        return self::protect(function () use ($d) {
            $p = $d->payload;
            self::check(is_array($p) && hash_equals($d->payload_sha256, MutationKey::hash($p))
                && ($p['batch_id'] ?? null) === $d->history_import_batch_id && ($p['row_id'] ?? null) === $d->staged_row_id
                && ($p['line'] ?? null) === $d->source_line && ($p['created_by'] ?? null) === $d->created_by
                && $d->request_sha256 === MutationKey::hash($p['input']));
            return $p;
        });
    }
    private static function check(bool $condition): void
    {
        RecordWriteRejected::unless($condition, 409, 'La evidencia histórica requiere revisión.');
    }
    private static function protect(callable $work): array
    {
        try { return $work(); }
        catch (RecordWriteRejected $e) { throw $e; }
        catch (\Throwable) { throw new RecordWriteRejected(409, 'La evidencia histórica requiere revisión.'); }
    }
}
