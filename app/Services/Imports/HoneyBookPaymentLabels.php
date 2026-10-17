<?php
namespace App\Services\Imports;
final class HoneyBookPaymentLabels
{
    public const VERSION='owner-honeybook-method-labels-v1';
    public static function display(string $raw,?string $frozenVersion=null):array
    {
        $zelle=mb_strtolower(trim($raw))==='cash';
        return ['raw_method'=>$raw,'display_method'=>$zelle?'Zelle (importado de HoneyBook, no verificado en banco)':$raw,
            'canonical_method'=>$zelle?'zelle':null,'label_map_version'=>self::VERSION,'historical_version_frozen'=>$frozenVersion===self::VERSION,'funds_verified'=>false];
    }
}
