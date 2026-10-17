<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\Imports\{DiscardHoneyBookImport,ImportInput,ProfileHoneyBookImport,ReadHoneyBookImport,StageHoneyBookImport};
use Illuminate\Http\Request;
final class HoneyBookImportController extends Controller
{
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
