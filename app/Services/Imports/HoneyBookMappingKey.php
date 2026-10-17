<?php
namespace App\Services\Imports;
use App\Models\ImportServiceMapping;
use App\Services\Finance\MutationKey;
final class HoneyBookMappingKey
{
    public static function forSource(array $source):string
    {
        $normalize=static fn($v)=>mb_strtolower(trim(preg_replace('/[\\s\\p{Z}]+/u',' ',(string)$v)),'UTF-8');
        return MutationKey::hash(['honeybook-mapping-v1',$normalize($source['services']??''),$normalize($source['characters']??'')]);
    }
    public static function suggestion(array $source):?ImportServiceMapping
    {
        return ImportServiceMapping::where('source_key',self::forSource($source))->first()
            ??ImportServiceMapping::where('source_key',MutationKey::hash([$source['services']??'',$source['characters']??'']))->first();
    }
}
