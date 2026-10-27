<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\{Http, RateLimiter};
use Tests\TestCase;

class WebsiteAvailabilityTransportTest extends TestCase
{
    private string $secret;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26T12:00:00-05:00'));
        $this->secret = str_repeat('synthetic-availability-', 3);
        config(['waoo.sandbox_web_availability'=>true, 'waoo.availability_shared_secret'=>$this->secret, 'app.debug'=>true]);
        RateLimiter::clear('website-availability:'.hash('sha256','127.0.0.1'));
        Http::preventStrayRequests();
    }
    private function signed(string $target='/api/availability?date=2026-11-14', string $method='GET', string $body='', array $headers=[])
    {
        $timestamp=(string) now()->timestamp;
        $server=['HTTPS'=>'on','HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json',
            'HTTP_X_WAOO_TIMESTAMP'=>$timestamp,'HTTP_X_WAOO_SIGNATURE'=>'sha256='.hash_hmac('sha256',$timestamp."\n".$method."\n".$target."\n".$body,$this->secret),...$headers];
        return $this->call($method,$target,[],[],[],$server,$body);
    }
    public function test_day_signal_is_private_conservative_and_read_only_without_database_or_provider(): void
    {
        $queries=[];\Illuminate\Support\Facades\DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});
        $data=$this->signed()->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('Referrer-Policy','no-referrer')->json();
        $this->assertSame(['schema_version','scope','date','timezone','level','capacity_state','demand','requires_owner_approval','slots','alts','checked_at','expires_at','reservation_created'],array_keys($data));
        $this->assertSame('unknown',$data['capacity_state']);$this->assertSame('filling',$data['level']);
        $this->assertSame('weekend',$data['demand']);$this->assertFalse($data['requires_owner_approval']);
        $this->assertSame([],$data['slots']);$this->assertSame([],$data['alts']);$this->assertFalse($data['reservation_created']);
        $this->assertSame($data['checked_at'],$data['expires_at']);$this->assertSame([],$queries);Http::assertNothingSent();
    }
    public function test_disabled_production_and_missing_or_reused_secrets_fail_closed(): void
    {
        config(['waoo.sandbox_web_availability'=>false]);$this->signed()->assertStatus(503);
        config(['waoo.sandbox_web_availability'=>true,'waoo.availability_shared_secret'=>null,'waoo.lead_shared_secret'=>$this->secret]);$this->signed()->assertStatus(503);
        config(['waoo.availability_shared_secret'=>'short']);$this->signed()->assertStatus(503);
        config(['waoo.availability_shared_secret'=>$this->secret]);$this->app['env']='production';$this->signed()->assertStatus(503);
    }
    public function test_hmac_binds_the_exact_target_body_method_and_time_before_input_validation(): void
    {
        $target='/api/availability?date=2026-11-14';$t=(string)now()->timestamp;
        $signature='sha256='.hash_hmac('sha256',$t."\nGET\n".$target."\n",$this->secret);
        $h=['HTTP_X_WAOO_SIGNATURE'=>$signature];
        $this->signed($target.'&zip=77493','GET','',$h)->assertUnauthorized();
        $this->signed($target,'GET','changed',$h)->assertUnauthorized();
        $this->signed('/api/availability/check','POST','{}',$h)->assertUnauthorized();
        $this->signed('/api/availability?name=private','GET','',['HTTP_X_WAOO_SIGNATURE'=>'bad'])->assertUnauthorized()->assertExactJson(['error'=>'authentication_failed']);
        $stale=(string)(now()->timestamp-301);
        $this->signed($target,'GET','',['HTTP_X_WAOO_TIMESTAMP'=>$stale,'HTTP_X_WAOO_SIGNATURE'=>'sha256='.hash_hmac('sha256',$stale."\nGET\n".$target."\n",$this->secret)])->assertUnauthorized();
        $this->signed($target,'GET','',['HTTP_X_WAOO_SIGNATURE'=>strtoupper($signature)])->assertUnauthorized();
    }
    public static function invalidQueries(): array
    {
        return array_map(fn($q)=>[$q],[
            'date=2026-11-14&date=2026-11-15','date=2026-11-14&%64ate=2026-11-14',
            'date=2026-11-14&date[]=2026-11-14','date=2026-11-14&email=private%40example.test',
            'date=2026-11-14&zip=%ZZ','date=2026-11-14&zip=77493%','date=2026-11-14&zip=77493;alts=1',
            'date=tomorrow','date=2026-02-30','date=2026-10-25','date=2026-11-14&zip=77493-1234',
            'date=2026-11-14&alts=2','date=2026-11-14&service=0','date=2026-11-14&service=1e2',
        ]);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidQueries')]
    public function test_invalid_queries_never_echo_private_inputs(string $query): void
    {
        $this->signed('/api/availability?'.$query)->assertUnprocessable()->assertExactJson(['error'=>'invalid_request'])->assertHeader('Cache-Control','no-store, private');
    }
    public function test_encoded_query_is_authenticated_verbatim_and_retries_do_not_reserve(): void
    {
        $target='/api/availability?date=2026%2D11%2D14&zip=77493&alts=1';
        $this->signed($target)->assertOk()->assertJsonPath('alts',[]);
        $this->signed($target)->assertOk()->assertJsonPath('reservation_created',false);
    }
    public function test_weekend_demand_is_only_the_next_eight_weekend_dates_and_never_capacity(): void
    {
        $this->signed('/api/availability?date=2026-11-22')->assertOk()->assertJsonPath('demand','weekend');
        $this->signed('/api/availability?date=2026-11-28')->assertOk()->assertJsonPath('demand','normal')->assertJsonPath('level','unknown');
        $this->signed('/api/availability?date=2026-11-16')->assertOk()->assertJsonPath('level','unknown');
    }
    public function test_day_review_uses_local_fourteen_days_across_dst_with_strict_equality(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26T08:00:00-05:00'));
        $this->signed('/api/availability?date=2026-11-09')->assertOk()->assertJsonPath('requires_owner_approval',false);
        $this->travel(1)->seconds();
        $this->signed('/api/availability?date=2026-11-09')->assertOk()->assertJsonPath('requires_owner_approval',true);
    }
    public function test_post_is_bounded_authenticated_and_explicitly_unavailable_until_the_full_evaluator(): void
    {
        $this->signed('/api/availability/check','POST','{"candidate_starts":[]}')->assertStatus(503)->assertExactJson(['error'=>'evaluation_unavailable']);
        $this->signed('/api/availability/check','POST','{}',['CONTENT_TYPE'=>'text/plain'])->assertStatus(415);
        $this->signed('/api/availability/check','POST',str_repeat('x',16385))->assertStatus(413);
        $this->signed('/api/availability?date=2026-11-14','GET','{}')->assertUnprocessable();
    }
    public function test_staging_requires_https_and_head_is_not_a_signed_get_alias(): void
    {
        $this->app['env']='staging';$this->signed('/api/availability?date=2026-11-14','GET','',['HTTPS'=>'off'])->assertForbidden();
        $this->app['env']='testing';$this->signed('/api/availability?date=2026-11-14','HEAD')->assertStatus(405);
    }
    public function test_failed_authentication_and_both_routes_share_the_same_rate_budget(): void
    {
        for($i=0;$i<59;$i++)$this->signed('/api/availability?date=2026-11-14','GET','',['HTTP_X_WAOO_SIGNATURE'=>'bad'])->assertUnauthorized();
        $this->signed('/api/availability/check','POST','{}')->assertStatus(503);
        $this->signed()->assertStatus(429)->assertHeader('Retry-After')->assertExactJson(['error'=>'rate_limited']);
        $this->travel(61)->seconds();$this->signed()->assertOk();
    }
    public function test_browser_origins_get_no_cors_permission_or_session_authority(): void
    {
        $this->signed('/api/availability?date=2026-11-14','GET','',['HTTP_ORIGIN'=>'https://untrusted.example.test'])
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin')->assertHeaderMissing('Set-Cookie');
        $this->call('OPTIONS','/api/availability',[],[],[],['HTTP_ORIGIN'=>'https://untrusted.example.test','HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'GET'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->call('OPTIONS','/api/leads',[],[],[],['HTTP_ORIGIN'=>'https://untrusted.example.test','HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'POST'])
            ->assertHeader('Access-Control-Allow-Origin','*'); // Existing lead transport policy stays unchanged.
    }
    public function test_debug_errors_and_unsupported_methods_never_expose_payloads_or_traces(): void
    {
        $this->app->bind(\App\Services\Availability\ReadWebsiteDay::class,function(){throw new \RuntimeException('private fixture: coordinates/secret/SQL');});
        $this->signed()->assertStatus(503)->assertExactJson(['error'=>'availability_unavailable']);
        $this->call('PUT','/api/availability?secret=private')->assertStatus(405)->assertExactJson(['error'=>'method_not_allowed'])->assertHeader('Cache-Control','no-store, private');
    }
    public function test_published_website_signing_vector_matches_the_actual_transport(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::createFromTimestampUTC(1793034000));
        $this->secret='synthetic-availability-example-not-a-real-secret';
        config(['waoo.availability_shared_secret'=>$this->secret]);
        $this->signed('/api/availability?date=2026-11-14&zip=77493&alts=1','GET','',[
            'HTTP_X_WAOO_SIGNATURE'=>'sha256=92714e66748f2dce2588ff1c3ffff2fdf900de219943730e7894d2c181eb36b2',
        ])->assertOk()->assertJsonPath('capacity_state','unknown');
    }
    public function test_auth_window_edges_and_target_budget_are_exact(): void
    {
        $target='/api/availability?date=2026-11-14';
        foreach([-300,300] as $offset){
            $timestamp=(string)(now()->timestamp+$offset);
            $this->signed($target,'GET','',['HTTP_X_WAOO_TIMESTAMP'=>$timestamp,'HTTP_X_WAOO_SIGNATURE'=>'sha256='.hash_hmac('sha256',$timestamp."\nGET\n".$target."\n",$this->secret)])->assertOk();
        }
        $this->signed($target.'&zip='.str_repeat('0',2048))->assertStatus(413)->assertExactJson(['error'=>'payload_too_large']);
        $this->signed($target,'GET','',['HTTP_X_HTTP_METHOD_OVERRIDE'=>'POST'])->assertStatus(405);
    }
}
