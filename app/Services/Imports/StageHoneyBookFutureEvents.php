<?php
namespace App\Services\Imports;
use App\Models\{ImportBatch,User};
use Illuminate\Http\UploadedFile;
final class StageHoneyBookFutureEvents
{
    public function execute(User $actor,UploadedFile $file,string $key):ImportBatch
    {
        return app(StageHoneyBookImport::class)->execute($actor,['files'=>[['role'=>'projects','file'=>$file]],'profile'=>HoneyBookFutureSource::profile()],$key);
    }
}
