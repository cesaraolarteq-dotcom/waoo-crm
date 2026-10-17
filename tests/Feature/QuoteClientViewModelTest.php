<?php
namespace Tests\Feature;
use App\Models\Quote;
use App\Services\Quotes\{ReadQuoteViewModel,ReviseQuote};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\QuoteScenario;
use Tests\TestCase;
class QuoteClientViewModelTest extends TestCase
{
    use DatabaseMigrations,QuoteScenario;
    protected function setUp():void{parent::setUp();$this->scenario();}
    public function test_http_vm_exact_schema_locale_formatting_whitelist_and_frozen_consent():void
    {
        $p=$this->prepare();auth()->guard('web')->logout();
        config(['waoo.quote_acceptance_consent'=>[]]); // Existing presentation retains approved frozen copy, never draft config.
        foreach(['es','en'] as $lang){
            $body=$this->withSession(['portal_grant_id'=>$p['portal_grant_id']])->getJson('/portal/api/quote?lang='.$lang)->assertOk()->assertHeader('Cache-Control','no-store, private')->json();$vm=$body['view_model'];
            $this->assertSame(['lang','first_name','test_mode','lang_url','quote','consent','prefill','actions'],array_keys($vm));
            $this->assertSame(['number','revision','state','valid_until','when','where','lines','travel','discounts','total','deposit','pdf_url'],array_keys($vm['quote']));
            $this->assertSame(['name','duration','characters','amount'],array_keys($vm['quote']['lines'][0]));
            $this->assertSame($lang,$vm['lang']);$this->assertTrue($vm['test_mode']);$this->assertSame('open',$vm['quote']['state']);
            $this->assertSame('$100.00',$vm['quote']['total']);$this->assertSame('$50.00',$vm['quote']['deposit']);$this->assertSame('$25.01',$vm['quote']['discounts'][0]['amount']);
            $this->assertSame('/portal/api/quote/accept',$vm['actions']['accept']);$this->assertSame('/portal/api/quote/reject',$vm['actions']['reject']);
            $this->assertSame('Fixture ES accept this recorded quote.',$vm['consent']['text']);$this->assertSame('',$vm['prefill']['email']);
            $this->assertMatchesRegularExpression('/(?:CDT|CST)/',$vm['quote']['when']);
            foreach(['PRIVATE_','requirements_snapshot','approved_by','client@example.test','phone','long_description'] as $secret)$this->assertStringNotContainsString($secret,json_encode($vm));
            $this->assertSame(hash('sha256',$vm['consent']['text']),$body['consents']['accepted']['sha256']);
        }
        $this->getJson('/portal/api/quote?lang[]=en')->assertUnprocessable();$this->getJson('/portal/api/quote?lang=fr')->assertUnprocessable();
        $this->assertDatabaseCount('quote_decisions',0);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('resource_reservations',0);
    }
    public function test_states_admin_actions_and_superseded_draft_never_enable_decisions():void
    {
        $p=$this->prepare();$c=$this->client($p['portal_grant_id']);
        $this->assertNull(app(ReadQuoteViewModel::class)->execute($this->client($p['portal_grant_id'],true))['actions']['accept']);
        foreach(['accepted','rejected','expired'] as $state){Quote::whereKey($this->offer->quote_id)->update(['status'=>$state]);$vm=app(ReadQuoteViewModel::class)->execute($c);$this->assertSame($state,$vm['quote']['state']);$this->assertNull($vm['actions']['accept']);$this->assertNull($vm['actions']['reject']);}
        Quote::whereKey($this->offer->quote_id)->update(['status'=>'sent']);$this->event->increment('version');
        $vm=app(ReadQuoteViewModel::class)->execute($c);$this->assertSame('superseded',$vm['quote']['state']);$this->assertNull($vm['quote']['pdf_url']);$this->assertNull($vm['actions']['accept']);
    }
    public function test_real_accepted_decision_is_projected_and_language_does_not_rewrite_frozen_payload():void
    {
        $p=$this->prepare();$this->acceptOffer($p);$vm=app(ReadQuoteViewModel::class)->execute($this->client($p['portal_grant_id']),'en');
        $this->assertSame('accepted',$vm['quote']['state']);$this->assertSame('$100.00',$vm['quote']['total']);$this->assertNull($vm['actions']['accept']);
        $this->assertSame('es',\App\Models\QuotePresentation::sole()->payload['locale']);
    }
    public function test_revised_offer_revokes_old_link_instead_of_disclosing_new_revision():void
    {
        $p=$this->prepare();app(ReviseQuote::class)->execute($this->owner,$this->offer->quote_id,['expected_version'=>2,'expected_event_version'=>1,'discounts'=>[]],(string)Str::uuid());
        auth()->guard('web')->logout();$this->withSession(['portal_grant_id'=>$p['portal_grant_id']])->getJson('/portal/api/quote')->assertForbidden();
    }
}
