<?php
namespace App\Services\Imports;
use App\Models\{ImportBatch,ImportPreviewVersion};
use App\Services\Finance\MutationKey;
final class ImportReceipt
{
    public static function replay(object $receipt):ImportBatch
    {
        $v=ImportPreviewVersion::findOrFail($receipt->result_id);return self::result(ImportBatch::findOrFail($v->import_batch_id),$v);
    }
    public static function store(ImportBatch $batch,string $scope,string $key,string $hash):ImportBatch
    {
        $v=ImportPreviewVersion::where('import_batch_id',$batch->id)->where('version',$batch->version)->lockForUpdate()->firstOrFail();
        MutationKey::store($scope,$key,$hash,'import_preview_version',$v->id);return self::result($batch,$v);
    }
    private static function result(ImportBatch $batch,ImportPreviewVersion $v):ImportBatch
    {
        $batch->setAttribute('mutation_result',['batch_id'=>$batch->id,'preview_version'=>$v->version,'preview_sha256'=>$v->preview_sha256,
            'profile_sha256'=>$v->profile_sha256,'historical'=>$batch->version!==$v->version]);return $batch;
    }
}
