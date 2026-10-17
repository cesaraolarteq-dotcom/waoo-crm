<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\Imports\{DiscardHoneyBookImport,ImportInput,ProfileHoneyBookImport,ReadHoneyBookImport,StageHoneyBookImport};
use Illuminate\Http\Request;
final class HoneyBookImportController extends Controller
{
    public function future(Request $r,ReadHoneyBookImport $read)
    {
        $d=ImportInput::input($r->all(),['file'=>'required|file']);
        $b=app(\App\Services\Imports\StageHoneyBookFutureEvents::class)->execute($r->user(),$d['file'],(string)$r->header('Idempotency-Key'));
        return response()->json([...$read->show($r->user(),$b->id),'mutation_result'=>$b->mutation_result],201);
    }
    public function commit(Request $r,int $batch,int $row)
    { return ['link'=>app(\App\Services\Imports\CommitHoneyBookEvent::class)->execute($r->user(),$batch,$row,$r->all(),(string)$r->header('Idempotency-Key'))]; }
    public function coverage(Request $r,int $batch)
    { return app(\App\Services\Imports\ReadImportCoverage::class)->execute($r->user(),$batch); }
    public function finalize(Request $r,int $batch)
    { return ['batch'=>app(\App\Services\Imports\FinalizeHoneyBookImport::class)->execute($r->user(),$batch,$r->all(),(string)$r->header('Idempotency-Key'))]; }
    public function imported(Request $r,int $event)
    { return app(\App\Services\Imports\ReadImportedEvent::class)->execute($r->user(),$event); }
    public function confirm(Request $r,int $event)
    { return ['event'=>app(\App\Services\Imports\ConfirmImportedCapacity::class)->execute($r->user(),$event,$r->all(),(string)$r->header('Idempotency-Key'))]; }
    public function mapping(Request $r,int $batch,int $row)
    {
        ImportInput::actor($r->user());$d=\App\Models\ImportRow::whereKey($row)->where('import_batch_id',$batch)->firstOrFail()->source;
        abort_unless(isset($d['services'],$d['characters']),409);
        return ['suggestion'=>\App\Models\ImportServiceMapping::where('source_key',\App\Services\Finance\MutationKey::hash([$d['services'],$d['characters']]))->first()];
    }
    public function index(Request $r,ReadHoneyBookImport $read){return $read->index($r->user(),$r->query());}
    public function show(Request $r,int $batch,ReadHoneyBookImport $read){return $read->show($r->user(),$batch);}
    public function rows(Request $r,int $batch,ReadHoneyBookImport $read){return $read->rows($r->user(),$batch,$r->query());}
    public function stage(Request $r,StageHoneyBookImport $stage,ReadHoneyBookImport $read)
    {
        $d=$this->input($r);$b=$stage->execute($r->user(),$d,(string)$r->header('Idempotency-Key'));return response()->json([...$read->show($r->user(),$b->id),'mutation_result'=>$b->mutation_result],201);
    }
    public function profile(Request $r,int $batch,ProfileHoneyBookImport $profile,ReadHoneyBookImport $read)
    {
        $b=$profile->execute($r->user(),$batch,$this->input($r),(string)$r->header('Idempotency-Key'));return [...$read->show($r->user(),$b->id),'mutation_result'=>$b->mutation_result];
    }
    public function discard(Request $r,int $batch,DiscardHoneyBookImport $discard)
    {
        return ['batch'=>$discard->execute($r->user(),$batch,$r->all(),(string)$r->header('Idempotency-Key'))];
    }
    private function input(Request $r):array
    {
        $d=$r->all();if(is_string($d['profile']??null)){try{$d['profile']=json_decode($d['profile'],true,flags:JSON_THROW_ON_ERROR);}catch(\JsonException){ImportInput::fail('profile','invalid_json');}}return $d;
    }
}
