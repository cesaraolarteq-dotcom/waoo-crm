<?php
namespace Tests\Feature;

use App\Models\{FinancialRevision, PriceVersion, QuickStripeLease, Resource, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Records\{ConfirmCapacity, SaveVariant};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Queue, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Cross-path regressions for Claude #200. Synthetic data only; no provider or deployment. */
class ExtraTimeAuditRegressionTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private PriceVersion $price;
    private CarbonImmutable $created;

    protected function setUp(): void
    {
        parent::setUp();
        $this->created = CarbonImmutable::parse('2026-11-04T10:00:00-06:00'); $this->travelTo($this->created);
        config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true,'waoo.sandbox_quick_stripe'=>true,
            'waoo.sandbox_quick_payments'=>true,'app.url'=>'https://crm.example.test']);
        Http::preventStrayRequests(); Queue::fake();
        $this->owner = User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $v=ServiceVariant::create(['name'=>'Música y Juegos','status'=>'active','duration_minutes'=>120,'setup_minutes'=>15,'breakdown_minutes'=>15,
            'requirements'=>[['kind'=>'staff','skill'=>'host','quantity'=>1]]]);
        $this->price=PriceVersion::create(['service_variant_id'=>$v->id,'amount_cents'=>52500,'currency'=>'USD','created_by'=>$this->owner->id]);
        Resource::create(['code'=>'HOST-1','name'=>'Sofía Méndez','kind'=>'staff','status'=>'active','skills'=>['host','driver']]);
    }

    protected function tearDown(): void
    {
        // This disposable synthetic database only; never weaken production rollback/evidence protections.
        if ($this->app && Schema::hasTable('quick_payment_bookings')) {
            foreach (['quick_payment_notifications','quick_payment_receipts','quick_payment_attempts','quick_payment_events','quick_payment_bookings'] as $t) DB::table($t)->delete();
            DB::table('quick_stripe_checkout_requests')->delete();
        }
        parent::tearDown();
    }

    private function input(CarbonImmutable $start): array
    {
        return ['new_customer'=>['name'=>'María González','phone'=>'+13465550100','email'=>'maria@example.test','locale'=>'es'],
            'title'=>'Cumpleaños de Sofía','event_type'=>'birthday','starts_at'=>$start->utc()->toIso8601String(),'ends_at'=>$start->addHours(2)->utc()->toIso8601String(),
            'timezone'=>'America/Chicago','postal_code'=>'77493','address'=>'123 Example St','travel_fee_cents'=>0,
            'services'=>[['price_version_id'=>$this->price->id]]];
    }

    public function test_time_extension_can_be_created_through_the_actual_catalog_service(): void
    {
        $variant=app(SaveVariant::class)->execute($this->owner,['name'=>'Hora extra','status'=>'active','duration_minutes'=>60,
            'setup_minutes'=>0,'breakdown_minutes'=>0,'extends_event'=>true,'requirements'=>[]]);
        $this->assertTrue($variant->extends_event);
        $this->assertTrue(\App\Services\Records\CharacterRecipe::compile($variant,[],'services.0.characters')['ready']);
    }

    public function test_new_tomorrow_booking_cannot_freeze_a_fifty_percent_payment_against_a_full_payment_target(): void
    {
        $start=CarbonImmutable::parse('2026-11-05T15:00:00-06:00'); // 29h notice: satisfies the new 24h rule
        $input=$this->input($start);$input['deposit_received']=false;
        app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());
        $finance=FinancialRevision::sole();
        $this->assertSame(52500,$finance->deposit_target_cents);
        $this->assertSame($finance->deposit_target_cents,QuickStripeLease::sole()->payload['terms']['principal_cents']);
    }

    public function test_a_booking_paid_weeks_ago_keeps_its_frozen_deposit_when_owner_confirms_it_the_day_before(): void
    {
        $start=CarbonImmutable::parse('2026-11-24T15:00:00-06:00');
        $received=$this->created->subMinute()->toIso8601String();
        $input=$this->input($start);$input['deposit']=['method'=>'zelle','amount_cents'=>26250,'reference'=>'EXTRA-AUDIT-1','received_at'=>$received,'verified_received'=>true];
        $booking=app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());
        $this->assertSame(26250,FinancialRevision::sole()->deposit_target_cents);
        $this->travelTo(CarbonImmutable::parse('2026-11-23T10:00:00-06:00')); // 29h before the existing party; balance is not due yet
        $event=$booking->event->fresh();
        $saved=app(ConfirmCapacity::class)->execute($this->owner,$event->id,['expected_version'=>$event->version,'staff_later'=>true,'resource_ids'=>[],
            'payment'=>['method'=>'external_zelle','amount_cents'=>26250,'reference'=>'EXTRA-AUDIT-CONFIRM','received_at'=>$received],
            'review'=>['short_notice_reason'=>'Confirmación administrativa de una reserva con depósito recibido al crearla.','transfers'=>[]]]);
        $this->assertSame('capacity_reserved',$saved->status);
        $this->assertSame(26250,FinancialRevision::sole()->deposit_target_cents);
    }
}
