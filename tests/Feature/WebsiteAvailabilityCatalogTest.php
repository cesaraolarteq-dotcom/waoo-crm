<?php
namespace Tests\Feature;

use App\Models\ServiceVariant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue, RateLimiter};
use Tests\TestCase;

class WebsiteAvailabilityCatalogTest extends TestCase
{
    use DatabaseMigrations;
    public function test_catalog_ids_must_be_active_and_reads_never_disclose_or_mutate_domain_data(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26T12:00:00-05:00'));
        $secret=str_repeat('synthetic-availability-',3);
        config(['waoo.sandbox_web_availability'=>true,'waoo.availability_shared_secret'=>$secret]);
        RateLimiter::clear('website-availability:'.hash('sha256','127.0.0.1'));
        Http::preventStrayRequests();Mail::fake();Queue::fake();
        $variant=ServiceVariant::create(['name'=>'Catálogo privado de María','status'=>'active','duration_minutes'=>120,'requirements'=>[]]);
        $hidden=ServiceVariant::create(['name'=>'Variante privada de Sofía','status'=>'inactive','duration_minutes'=>120,'requirements'=>[]]);
        $queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});
        $read=function($id)use($secret){
            $target='/api/availability?date=2026-11-14&service='.$id;
            $timestamp=(string)now()->timestamp;
            return $this->call('GET',$target,[],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_X_WAOO_TIMESTAMP'=>$timestamp,
                'HTTP_X_WAOO_SIGNATURE'=>'sha256='.hash_hmac('sha256',$timestamp."\nGET\n".$target."\n",$secret)]);
        };
        $response=$read($variant->id)->assertOk()->assertJsonPath('capacity_state','unknown')->assertJsonPath('slots',[]);
        $this->assertStringNotContainsString($variant->name,$response->getContent());
        $this->assertArrayNotHasKey('service_variant_id',$response->json());
        $read($hidden->id)->assertUnprocessable()->assertExactJson(['error'=>'invalid_request']);
        $read($hidden->id+999)->assertUnprocessable()->assertExactJson(['error'=>'invalid_request']);
        $this->assertNotEmpty($queries);
        foreach($queries as $query)$this->assertMatchesRegularExpression('/^select\b/i',$query);
        Http::assertNothingSent();Mail::assertNothingSent();Queue::assertNothingPushed();
    }
}
