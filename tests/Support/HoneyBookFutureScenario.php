<?php
namespace Tests\Support;
use App\Models\{ImportRow,PriceVersion,ServiceVariant,User};
use App\Services\Imports\{HoneyBookFutureSource,StageHoneyBookFutureEvents};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Queue,Storage};
use Illuminate\Support\Str;
trait HoneyBookFutureScenario
{
    private User $owner;
    private PriceVersion $price;
    private function futureScenario():void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-17T12:00:00Z'));Storage::fake('local');Queue::fake();config(['waoo.sandbox_imports'=>true,'waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true,'app.url'=>'https://localhost']);
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$this->actingAs($this->owner);
        $v=ServiceVariant::create(['name'=>'Current synthetic catalog label','status'=>'active','duration_minutes'=>60,'requirements'=>[['kind'=>'staff','quantity'=>1,'skill'=>'host'],['kind'=>'equipment','quantity'=>1,'equipment_kind'=>'speaker']]]);
        $this->price=PriceVersion::create(['service_variant_id'=>$v->id,'amount_cents'=>99999,'currency'=>'USD','created_by'=>$this->owner->id]);
    }
    private function futureRow(array $change=[]):array
    {
        return [...array_combine(HoneyBookFutureSource::HEADERS,['HB-FAKE-1','Fictional Source Party','confirmed','Fictional','Source Client','+13465550100','fixture-source@example.test','2026-12-20','15:00','16:00','123 Fictional Venue TX','77433','Historical Package + Extra','',
            '100.01','25.00','5.01','120.00','60.00','60.00','2026-12-19','yes','2026-10-01|70.00|Zelle;2026-10-02|-10.00|source adjustment','PRIVATE_SOURCE_NOTE_FIXTURE']),...$change];
    }
    private function futureFile(array $rows,?array $headers=null):UploadedFile
    {
        $headers??=HoneyBookFutureSource::HEADERS;$f=fopen('php://temp','w+');fputcsv($f,$headers,',','"','');foreach($rows as $r)fputcsv($f,array_map(fn($h)=>$r[$h]??'',$headers),',','"','');rewind($f);$bytes=stream_get_contents($f);fclose($f);return UploadedFile::fake()->createWithContent('fictional-future.csv',$bytes);
    }
    private function stageFuture(array $change=[],?string $key=null)
    {
        return app(StageHoneyBookFutureEvents::class)->execute($this->owner,$this->futureFile([$this->futureRow($change)]),$key??(string)Str::uuid());
    }
    private function commitInput($batch,ImportRow $row,array $change=[]):array
    {
        return ['expected_batch_version'=>$batch->version,'expected_row_version'=>$row->version,'expected_preview_sha256'=>$row->preview_sha256,'source_confirmed'=>true,'source_evidence_reviewed'=>true,
            'setup_minutes'=>15,'breakdown_minutes'=>15,'services'=>[['price_version_id'=>$this->price->id,'expected_variant_version'=>1]],'create_separate_customer'=>true,'new_customer_locale'=>'es',...$change];
    }
}
