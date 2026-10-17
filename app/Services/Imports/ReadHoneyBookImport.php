<?php
namespace App\Services\Imports;
use App\Exceptions\RecordWriteRejected;
use App\Models\{ImportBatch,ImportFile,ImportRow,User};
use Illuminate\Pagination\LengthAwarePaginator;
final class ReadHoneyBookImport
{
    public function index(User $actor,array $input):LengthAwarePaginator
    {
        ImportInput::actor($actor);$d=ImportInput::input($input,['page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:50','status'=>'sometimes|in:prepared,needs_review,cleanup_pending,discarded,partially_committed,imported_cleanup_pending,imported']);
        $q=ImportBatch::query()->orderByDesc('id');if(isset($d['status']))$q->where('status',$d['status']);
        return $this->paginate($q,$d,['id','version','status','profile_sha256','preview_sha256','reconciliation','created_by','received_at']);
    }
    public function show(User $actor,int $batchId):array
    {
        ImportInput::actor($actor);$b=ImportBatch::findOrFail($batchId);
        return ['batch'=>$b,'files'=>ImportFile::where('import_batch_id',$batchId)->orderBy('role')->get(),'reconciliation'=>$b->reconciliation];
    }
    public function rows(User $actor,int $batchId,array $input):LengthAwarePaginator
    {
        ImportInput::actor($actor);$b=ImportBatch::findOrFail($batchId);RecordWriteRejected::unless(in_array($b->status,['prepared','needs_review','partially_committed'],true),410,'El contenido de esta importación fue descartado.');
        $d=ImportInput::input($input,['page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:50','role'=>'sometimes|in:projects,customers,payments','status'=>'sometimes|in:ready,needs_mapping,conflict,skipped']);
        $q=ImportRow::where('import_batch_id',$batchId)->orderBy('id');foreach(['role','status'] as $f)if(isset($d[$f]))$q->where($f,$d[$f]);return $this->paginate($q,$d);
    }
    private function paginate($query,array $data,array $columns=['*']):LengthAwarePaginator
    {
        $size=(int)($data['per_page']??25);$last=max(1,(int)ceil((clone $query)->count()/$size));$page=min((int)($data['page']??1),$last);return $query->paginate($size,$columns,'page',$page);
    }
}
