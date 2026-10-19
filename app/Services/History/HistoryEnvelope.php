<?php
namespace App\Services\History;

use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoryImportBatch, HistoryImportRow};
use App\Services\Finance\MutationKey;

final class HistoryEnvelope
{
    public static function batch(HistoryImportBatch $batch): array
    {
        try {
            $m = $batch->manifest;
            self::check(is_array($m) && ($m['batch_id'] ?? null) === $batch->id
                && ($m['version'] ?? null) === $batch->version && ($m['state'] ?? null) === $batch->state
                && ($m['profile'] ?? null) === $batch->profile && ($m['created_by'] ?? null) === $batch->created_by
                && ($m['source_sha256'] ?? null) === $batch->source_sha256 && ($m['byte_count'] ?? null) === $batch->byte_count
                && ($m['cutoff'] ?? null) === $batch->cutoff->toIso8601String()
                && hash_equals($batch->coverage_sha256, MutationKey::hash($m)));
            if ($batch->state !== 'discarded') {
                $a = $batch->artifact;
                self::check(is_array($a) && ($a['batch_id'] ?? null) === $batch->id && is_string($a['bytes'] ?? null)
                    && strlen($a['bytes']) === $batch->byte_count && hash_equals($batch->source_sha256, hash('sha256', $a['bytes'])));
                self::check($batch->active_fingerprint === $batch->source_sha256
                    && HistoryImportRow::where('history_import_batch_id', $batch->id)->count() === count($m['rows']));
            } else self::check($batch->artifact === null && $batch->active_fingerprint === null
                && !HistoryImportRow::where('history_import_batch_id', $batch->id)->exists());
            return $m;
        } catch (RecordWriteRejected $e) { throw $e; }
        catch (\Throwable) { throw new RecordWriteRejected(409, 'La evidencia privada del historial requiere revisión.'); }
    }

    public static function row(HistoryImportRow $row, array $manifest): array
    {
        try {
            $p = $row->payload;
            $expected = $manifest['rows'][(string)$row->id] ?? null;
            self::check(is_array($p) && ($p['batch_id'] ?? null) === $row->history_import_batch_id
                && ($p['id'] ?? null) === $row->id && ($p['version'] ?? null) === $row->version
                && ($p['line'] ?? null) === $row->source_line && hash_equals($row->preview_sha256, MutationKey::hash($p))
                && $expected === $row->preview_sha256);
            return $p;
        } catch (RecordWriteRejected $e) { throw $e; }
        catch (\Throwable) { throw new RecordWriteRejected(409, 'La evidencia privada del historial requiere revisión.'); }
    }

    private static function check(bool $valid): void
    {
        RecordWriteRejected::unless($valid, 409, 'La evidencia privada del historial requiere revisión.');
    }

    public static function metadata(HistoryImportBatch $b): array
    {
        return ['batch_id' => $b->id, 'version' => $b->version, 'state' => $b->state, 'profile' => $b->profile];
    }
}
