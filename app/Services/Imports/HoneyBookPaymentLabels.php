<?php
namespace App\Services\Imports;
final class HoneyBookPaymentLabels
{
    public const VERSION='owner-honeybook-method-labels-v2';
    public static function current(string $raw, ?string $sourceVersion=null):array {
        return [...self::display($raw,$sourceVersion===self::VERSION?$sourceVersion:null),'source_label_map_version'=>$sourceVersion];
    }
    public static function display(string $raw,?string $frozenVersion=null):array
    {
        $legacy=$frozenVersion==='owner-honeybook-method-labels-v1';
        $zelle=mb_strtolower(trim($raw))==='cash';
        return ['raw_method'=>$raw,'display_method'=>$zelle?($legacy?'Zelle (importado de HoneyBook, no verificado en banco)':'Zelle/efectivo (HoneyBook)'):$raw,
            'canonical_method'=>$zelle&&$legacy?'zelle':null,'label_map_version'=>$legacy?$frozenVersion:self::VERSION,'historical_version_frozen'=>$legacy||$frozenVersion===self::VERSION,'funds_verified'=>false];
    }
}
