<?php
namespace Tests\Support;

use App\Models\{Customer, Event, PortalGrant, PriceVersion, Quote, QuotePresentation, QuoteRevision, ServiceVariant, User};
use App\Services\Quotes\{CreateQuote, DecideQuote, SendQuote};
use App\Services\Portal\PortalContext;
use App\Services\Records\SaveEventDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Queue, Storage};
use Illuminate\Support\Str;

trait QuoteScenario
{
    private User $owner;
    private Event $event;
    private QuoteRevision $offer;
    private PriceVersion $price;

    private function scenario(): void
    {
        config(['waoo.sandbox_quotes'=>true,'waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true,'app.url'=>'https://localhost',
            'waoo.quote_acceptance_consent'=>['version'=>'fixture-decision-v1','es'=>['accepted'=>'Fixture ES accept this recorded quote.','rejected'=>'Fixture ES reject this recorded quote.'],
                'en'=>['accepted'=>'Fixture EN accept this recorded quote.','rejected'=>'Fixture EN reject this recorded quote.']]]);
        Queue::fake();Storage::fake('local');
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($this->owner);
        $customer=Customer::create(['name'=>'Fictional Quote Client','email'=>'client@example.test','phone'=>'346-555-0100','locale'=>'es']);
        $variant=ServiceVariant::create(['name'=>'Original Fictional Package','status'=>'active','duration_minutes'=>60,'long_description'=>'Safe <script>fixture</script> text.',
            'requirements'=>[['kind'=>'staff','quantity'=>1,'skill'=>'performer'],['kind'=>'equipment','quantity'=>1,'equipment_kind'=>'speaker']]]);
        $this->price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>10001,'currency'=>'USD','created_by'=>$this->owner->id]);
        $start=now()->addDays(20)->startOfHour();
        $this->event=app(SaveEventDraft::class)->execute($this->owner,['customer_id'=>$customer->id,'title'=>'Fictional Quote Party','starts_at'=>$start->toIso8601String(),
            'ends_at'=>$start->copy()->addHour()->toIso8601String(),'timezone'=>'America/Chicago','address'=>'123 Fictional Venue, TX 77433','postal_code'=>'77433',
            'setup_minutes'=>15,'breakdown_minutes'=>15,'travel_fee_cents'=>2500,'travel_fee_override_note'=>'PRIVATE_ROUTE_REASON manual fixture travel amount.',
            'services'=>[['price_version_id'=>$this->price->id]]]);
        $this->offer=app(CreateQuote::class)->execute($this->owner,['event_id'=>$this->event->id,'expected_event_version'=>$this->event->version,'owner_note'=>'PRIVATE_OWNER_NOTE',
            'discounts'=>[['label'=>'Visible adjustment','amount_cents'=>2501,'reason'=>'PRIVATE_DISCOUNT_REASON fixture approved adjustment.']]],(string)Str::uuid());
    }
    private function prepare(?string $key=null): array
    {
        $q=Quote::findOrFail($this->offer->quote_id);
        return app(SendQuote::class)->execute($this->owner,$q->id,['expected_version'=>$q->version,'expected_revision'=>$q->current_revision],$key??(string)Str::uuid());
    }
    private function client(int $grantId,bool $admin=false): PortalContext
    {
        $r=Request::create('https://localhost/portal/api/quote/accept','POST',[],[],[],['REMOTE_ADDR'=>'192.0.2.19','HTTP_USER_AGENT'=>'Fixture Quote Browser']);
        $r->setLaravelSession(app('session')->driver());$r->session()->put('portal_grant_id',$grantId);
        $r->setUserResolver(fn()=>$admin?$this->owner:null);return PortalContext::fromRequest($r);
    }
    private function decisionInput(array $prepared,string $outcome='accepted'): array
    {
        $p=QuotePresentation::findOrFail($prepared['presentation_id']);
        return ['expected_revision'=>$p->payload['revision'],'snapshot_sha256'=>$p->snapshot_sha256,'pdf_sha256'=>$p->pdf_sha256,
            'consent_version'=>$p->consents[$outcome]['version'],'consent_sha256'=>$p->consents[$outcome]['sha256'],
            'name'=>'Fictional Client','email'=>'client@example.test','consent'=>true,'outcome'=>$outcome];
    }
    private function viewPdf(array $prepared): void
    {
        $this->withSession(['portal_grant_id'=>$prepared['portal_grant_id']])->get('/portal/api/quote/pdf')->assertOk()->assertHeader('Content-Type','application/pdf');
    }
    private function acceptOffer(?array $prepared=null)
    {
        $prepared??=$this->prepare();$this->viewPdf($prepared);
        return app(DecideQuote::class)->execute($this->client($prepared['portal_grant_id']),$this->decisionInput($prepared),(string)Str::uuid());
    }
}
