<?php
namespace Tests\Feature;

use App\Models\{Customer,Event,Quote};
use App\Services\Billing\{BillingCompany,SaveEventBillingCompany};
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Quotes\{CreateQuote,QuoteArtifacts,QuoteProtocol,ReviseQuote};
use App\Services\Documents\PrintRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QuoteScenario;
use Tests\TestCase;
class EventBillingCompanyTest extends TestCase
{
    use RefreshDatabase,QuoteScenario;
    protected function setUp(): void {
        parent::setUp();$this->scenario();$this->event->customer->update(['name'=>'María González']);config(['waoo.sandbox_company'=>true]);
    }
    private function save(?array $company,array $changes=[],?string $key=null): array {
        $event=$this->event->fresh();return app(SaveEventBillingCompany::class)->execute($this->owner,$event->id,
            ['expected_event_version'=>$event->version,'expected_billing_version'=>$event->billing_company_version,'billing_company'=>$company,...$changes],$key??(string)Str::uuid());
    }
    public function test_company_is_encrypted_per_event_without_changing_person_or_capacity_version(): void {
        $person=Customer::sole()->getRawOriginal();$eventVersion=$this->event->version;
        $this->save(['name'=>'Celebraciones del Sol','email'=>'billing@example.test','address'=>'123 Example Road']);
        $event=$this->event->fresh();$this->assertSame($eventVersion,$event->version);$this->assertSame(1,$event->billing_company_version);
        $this->assertSame($person,Customer::sole()->getRawOriginal());$this->assertSame('Celebraciones del Sol',$event->billing_company['name']);
        $this->assertStringNotContainsString('Celebraciones',DB::table('events')->sole()->billing_company);
        $this->assertStringNotContainsString('Celebraciones',DB::table('audit_entries')->get()->toJson());
        $this->assertArrayNotHasKey('billing_company',$event->toArray());$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_stable_retry_stale_versions_validation_and_disabled_flag(): void {
        $key=(string)Str::uuid();$input=['expected_event_version'=>1,'expected_billing_version'=>0,'billing_company'=>['name'=>'Celebraciones del Sol']];$service=app(SaveEventBillingCompany::class);
        $first=$service->execute($this->owner,$this->event->id,$input,$key);$this->assertSame($first,$service->execute($this->owner,$this->event->id,$input,$key));
        try{$service->execute($this->owner,$this->event->id,$input,(string)Str::uuid());$this->fail('Expected stale');}catch(\App\Exceptions\RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        try{$this->save(['email'=>'billing@example.test']);$this->fail('Name required');}catch(\Illuminate\Validation\ValidationException){$this->assertTrue(true);}
        try{$this->save([]);$this->fail('Empty company must not pass');}catch(\App\Exceptions\RecordWriteRejected $e){$this->assertSame(422,$e->status);}
        config(['waoo.sandbox_company'=>false]);$this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);$this->save(null);
    }
    public function test_quote_snapshot_and_pdf_keep_company_and_person_frozen_after_an_edit(): void {
        $this->save(['name'=>'Celebraciones del Sol']);$r=app(ReviseQuote::class)->execute($this->owner,Quote::sole()->id,['expected_version'=>Quote::sole()->version,'expected_event_version'=>1,'discounts'=>[],'locale'=>'es'],(string)Str::uuid());
        $q=Quote::sole();$p=QuoteProtocol::payload($q,$r);$html=view('pdf.quote',['p'=>$p])->render();
        $this->assertStringContainsString('Facturar a:',$html);$this->assertStringContainsString('Celebraciones del Sol',$html);$this->assertStringContainsString('María González',$html);
        $artifact=app(QuoteArtifacts::class)->create($r->id,$p);$this->assertNotEmpty($artifact['pdf_sha256']);
        $this->save(['name'=>'Servicios de Luna']);$this->assertSame('Celebraciones del Sol',$r->fresh()->event_snapshot['billing_company']['name']);
        $this->expectException(\App\Exceptions\RecordWriteRejected::class);QuoteProtocol::assertSource($this->event->fresh(),$q,$r);
    }
    public function test_no_company_keeps_old_snapshot_shape_and_print_layout_bytes(): void {
        $r=app(ReviseQuote::class)->execute($this->owner,Quote::sole()->id,['expected_version'=>Quote::sole()->version,'expected_event_version'=>1,'discounts'=>[]],(string)Str::uuid());
        $this->assertArrayNotHasKey('billing_company',$r->event_snapshot);$this->assertArrayNotHasKey('billing_company_version',$r->event_snapshot);
        $renderer=app(PrintRenderer::class);$source='<html><body><p>Existing unchanged content</p>{{terms_html}}</body></html>';
        $plain=$renderer->html($source,'<p>Terms</p>',[]);$this->assertSame('<html><body><p>Existing unchanged content</p><p>Terms</p></body></html>',$plain);
        $with=$renderer->html($source,'<p>Terms</p>',['billing_company'=>['name'=>'Sol <script>','person'=>'María González','email'=>'','address'=>'']]);
        $this->assertStringContainsString('Facturar a / Bill to: Sol &lt;script&gt;',$with);$this->assertStringNotContainsString('Sol <script>',$with);
    }
    public function test_owner_screen_pins_both_versions_and_invoice_preview_is_neither_a_receipt_nor_a_send(): void {
        \Livewire\Livewire::test(\App\Filament\Resources\Events\Pages\ViewEvent::class,['record'=>$this->event->id])
            ->mountAction('billingCompany')->setActionData(['enabled'=>true,'name'=>'Celebraciones del Sol','email'=>'billing@example.test'])
            ->callMountedAction()->assertNotified('Datos de empresa guardados');
        app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>1],(string)Str::uuid());
        $response=$this->get('/admin/api/events/'.$this->event->id.'/billing-preview.pdf')->assertOk()->assertHeader('Content-Type','application/pdf')->assertHeader('Cache-Control','no-store, private');
        $this->assertStringStartsWith('%PDF-',$response->getContent());$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('outbound_messages',0);
        config(['waoo.sandbox_company'=>false]);$this->get('/admin/api/events/'.$this->event->id.'/billing-preview.pdf')->assertForbidden();
    }
    public function test_removing_company_retains_version_pin_for_new_quotes_and_audit_failure_rolls_back(): void {
        $this->save(['name'=>'Celebraciones del Sol']);$this->save(null);
        $r=app(ReviseQuote::class)->execute($this->owner,Quote::sole()->id,['expected_version'=>Quote::sole()->version,'expected_event_version'=>1,'discounts'=>[]],(string)Str::uuid());
        $this->assertSame(2,$r->event_snapshot['billing_company_version']);$this->assertNull($r->event_snapshot['billing_company']);
        QuoteProtocol::assertSource($this->event->fresh(),Quote::sole(),$r);
        DB::listen(function($q){if(str_contains($q->sql,'insert into `audit_entries`')&&in_array('event.billing_company_saved',$q->bindings,true))throw new \RuntimeException('Synthetic billing audit failure');});
        try{$this->save(['name'=>'Servicios de Luna']);$this->fail('Expected rollback');}catch(\RuntimeException $e){$this->assertSame('Synthetic billing audit failure',$e->getMessage());}
        $this->assertNull($this->event->fresh()->billing_company);$this->assertSame(2,$this->event->fresh()->billing_company_version);
    }
}
