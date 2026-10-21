<?php
namespace Tests\Feature;

use App\Exceptions\ResponsibilityRejected;
use App\Models\{Event, PortalGrant};
use App\Services\Portal\{IssuePortalGrant, RevealPortalGrantLink};
use App\Services\Responsibility\RequireCrmResponsibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

class ResponsibilityAccessTest extends TestCase
{
    use RefreshDatabase, QuoteScenario;
    protected function setUp(): void { parent::setUp(); $this->scenario(); }

    public function test_old_grants_cannot_be_revealed_or_authorize_payment_after_responsibility_changes(): void
    {
        $grant=app(IssuePortalGrant::class)->execute($this->owner,$this->event->id,['customer_id'=>$this->event->customer_id,'expires_at'=>now()->addDays(29)->toIso8601String()]);
        $client=$this->client($grant->id);$this->event->forceFill(['responsible_system'=>'honeybook'])->save();
        foreach ([fn()=>app(RevealPortalGrantLink::class)->execute($this->owner,$this->event->id,$grant->id),
            fn()=>$client->authorize($this->event->id,'checkout.create'),
            fn()=>app(\App\Services\Portal\SubmitZelleNotice::class)->execute($client,['financial_revision_id'=>1,'amount_cents'=>1000],(string)\Illuminate\Support\Str::uuid()),
            fn()=>app(\App\Services\Finance\RecordZellePayment::class)->execute($this->owner,$this->event->id,['financial_revision_id'=>1,'amount_cents'=>1000,'currency'=>'USD','reference'=>'Fixture bank ref','received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true],(string)\Illuminate\Support\Str::uuid()),fn()=>$client->authorize($this->event->id,'payments.read',true),
            fn()=>app(IssuePortalGrant::class)->execute($this->owner,$this->event->id,['customer_id'=>$this->event->customer_id,'expires_at'=>now()->addDays(29)->toIso8601String()])] as $action) {
            try { DB::transaction($action);$this->fail('HoneyBook payment must reject'); }
            catch(ResponsibilityRejected $e) { $this->assertSame(409,$e->getStatusCode()); }
        }
        $this->withSession(['portal_grant_id'=>$grant->id])->getJson('/portal/api/event')->assertConflict()->assertJsonPath('code','responsible_system_honeybook');
        $this->withSession(['portal_grant_id'=>$grant->id])->get('/portal/payment-return')->assertConflict();
        $this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('payment_intents',0);
    }
    public function test_quote_send_replay_is_not_a_honeybook_message_escape():void
    {
        $key=(string)\Illuminate\Support\Str::uuid();$this->prepare($key);$this->event->forceFill(['responsible_system'=>'honeybook'])->save();
        $this->expectException(ResponsibilityRejected::class);$this->prepare($key);
    }
    public function test_crm_default_and_mass_assignment_never_flip_responsibility():void
    {
        $this->assertSame('crm',$this->event->responsible_system);$this->assertSame('crm',RequireCrmResponsibility::forEvent($this->event->id)->responsible_system);
        $this->event->fill(['responsible_system'=>'honeybook'])->save();$this->assertSame('crm',$this->event->fresh()->responsible_system);
        $this->event->forceFill(['responsible_system'=>'honeybook'])->save();
        $this->event->fill(['responsible_system'=>'crm'])->save();$this->assertSame('honeybook',$this->event->fresh()->responsible_system);
    }
}
