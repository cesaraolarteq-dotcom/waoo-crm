<?php
namespace App\Services\Imports;
use App\Models\{ImportRow,ImportedProjectRevision};
final class HoneyBookSourceDifference
{
    public const VERSION='source-total-difference-v1';
    public static function delta(array $n):int
    {
        return $n['total_cents']-($n['package_cents']+$n['travel_cents']-$n['discount_cents']);
    }
    public static function covers(ImportRow $row,?ImportedProjectRevision $revision):bool
    {
        $e=$revision?->mapping['source_difference']??null;
        return $revision!==null&&hash_equals($revision->source_sha256,$row->normalized['source_sha256'])&&is_array($e)
            &&($e['policy_version']??null)===self::VERSION&&($e['code']??null)==='package_equation_mismatch'
            &&is_string($e['note']??null)&&mb_strlen(trim($e['note']))>=5&&isset($e['accepted_by'],$e['accepted_at'])
            &&($e['difference_cents']??null)===self::delta($row->normalized);
    }
}
