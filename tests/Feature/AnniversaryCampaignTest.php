<?php
namespace Tests\Feature;

use App\Models\{AnniversaryCode, Character, Customer, Event, FinancialRevision, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Campaigns\AnniversaryCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// ANNIV-01 (docs/plans/ANNIVERSARY-REBOOK.md, César 2026-10-29): one code per client, 10 % on the package only, 7 days, single use.
class AnniversaryCampaignTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private PriceVersion $price; private Character $character;

    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_anniversary' => true, 'waoo.sandbox_history' => true, 'app.url' => 'https://localhost']); Queue::fake(); Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-02T10:00:00-06:00'));
        $this->owner = User::factory()->create(['name' => 'Ana López', 'role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]); $this->actingAs($this->owner);
        $this->character = Character::create(['name' => 'Mickey', 'active' => true, 'outfits' => ['0']]);
        Resource::create(['code' => 'MICKEY-1', 'name' => 'Cabeza de Mickey', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->character->id, 'skills' => []]);
        $v = ServiceVariant::create(['name' => 'Música y juegos', 'status' => 'active', 'duration_minutes' => 120, 'character_quantity' => 1, 'requirements' => [],
            'character_options' => [['character_id' => $this->character->id, 'requirements' => [
                ['kind' => 'costume', 'character_id' => $this->character->id, 'quantity' => 1], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $this->price = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 30000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }

    protected function tearDown(): void
    {
        DB::table('event_service_characters')->delete();
        parent::tearDown();
    }

    private function client(string $name, ?string $email = 'maria@example.com'): Customer
    {
        return Customer::create(['name' => $name, 'email' => $email, 'phone' => '+1346555'.random_int(1000, 9999), 'locale' => 'en']);
    }

    private function pastParty(Customer $c, int $daysAgo, string $type = 'birthday', string $status = 'capacity_reserved'): Event
    {
        $s = now('America/Chicago')->subDays($daysAgo)->setTime(14, 0)->utc();
        $e = Event::create(['customer_id' => $c->id, 'title' => 'Fiesta', 'event_type' => $type, 'starts_at' => $s, 'ends_at' => $s->addHours(2), 'timezone' => 'America/Chicago', 'setup_minutes' => 30, 'breakdown_minutes' => 20]);
        $e->status = $status; $e->save();
        return $e;
    }

    private function book(Customer $c, ?string $code, int $days = 40, int $mickeys = 1): Event
    {
        $start = now('America/Chicago')->addDays($days)->setTime(12, 0)->utc();
        $b = app(QuickBook::class)->execute($this->owner, ['customer_id' => $c->id,
            'title' => 'Cumpleaños', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHours(2)->toIso8601String(),
            'timezone' => 'America/Chicago', 'address' => '123 Avenida del Parque', 'postal_code' => '77433', 'travel_fee_cents' => 4000, 'setup_minutes' => 30, 'breakdown_minutes' => 20,
            'services' => [['price_version_id' => $this->price->id, 'characters' => [['character_id' => $this->character->id, 'quantity' => $mickeys, 'outfit' => '0']]]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 15000, 'reference' => 'DEP-'.Str::random(8), 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true],
            ...($code !== null ? ['promo_code' => $code] : [])], (string) Str::uuid());
        return Event::findOrFail($b->event->id);
    }

    public function test_codes_are_issued_once_46_weeks_after_the_last_birthday_and_skips_are_recorded(): void
    {
        $maria = $this->client('María González');
        $this->pastParty($maria, 330);
        $lucia = $this->client('Lucía Hernández', 'lucia@example.com');
        $this->pastParty($lucia, 330); $this->pastParty($lucia, 0 - 20, status: 'capacity_reserved'); // already booked again
        $ana = $this->client('Ana Ramírez', null); $this->pastParty($ana, 330);
        $this->pastParty($this->client('Sofía Torres', 's@example.com'), 330, 'corporate');
        $this->pastParty($this->client('Elena Cruz', 'e@example.com'), 300); // 43 weeks: not yet
        $this->pastParty($this->client('Rosa Díaz', 'r@example.com'), 330, status: 'cancelled');

        $this->assertSame(['issued' => 1, 'skipped' => 2], app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago')));
        $this->assertSame(['issued' => 0, 'skipped' => 0], app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago')), 'idempotent');
        $code = AnniversaryCode::where('customer_id', $maria->id)->firstOrFail();
        $this->assertSame('ready', $code->state);
        $this->assertMatchesRegularExpression('/^WAOO-[A-HJ-NP-Z2-9]{5}$/', $code->code);
        $this->assertSame('2026-11-09 23:59:59', $code->expires_at->setTimezone('America/Chicago')->format('Y-m-d H:i:s'));
        $this->assertSame('already_rebooked', AnniversaryCode::where('customer_id', $lucia->id)->value('skip_reason'));
        $this->assertSame('no_email', AnniversaryCode::where('customer_id', $ana->id)->value('skip_reason'));
        $this->assertSame(['issued' => 1, 'sent' => 0, 'redeemed' => 0], array_intersect_key(AnniversaryCampaign::stats(), array_flip(['issued', 'sent', 'redeemed'])));
    }

    public function test_the_code_takes_10_percent_off_the_package_only_once_for_its_own_client(): void
    {
        $maria = $this->client('María González');
        $this->pastParty($maria, 330);
        app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago'));
        $code = AnniversaryCode::where('customer_id', $maria->id)->value('code');

        $e = $this->book($maria, strtolower(' '.$code.' '));
        $rev = FinancialRevision::where('event_id', $e->id)->orderByDesc('revision')->firstOrFail();
        $this->assertSame(3000, $rev->discount_cents, '10 % of $300 package');
        $this->assertSame(30000 + 4000 - 3000, $rev->total_cents, 'travel fee charged in full');
        $this->assertSame('anniversary', $rev->discounts_snapshot[0]['kind']);
        $this->assertSame('redeemed', AnniversaryCode::where('code', $code)->value('state'));

        try { $this->book($maria, $code, 60); $this->fail('second use'); } catch (ValidationException $ex) { $this->assertStringContainsString('ya se usó', $ex->errors()['promo_code'][0]); }
        $other = $this->client('Lucía Hernández', 'l@example.com');
        $this->pastParty($other, 331);
        app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago'));
        $theirs = AnniversaryCode::where('customer_id', $other->id)->value('code');
        try { $this->book($maria, $theirs, 70); $this->fail('other client'); } catch (ValidationException $ex) { $this->assertStringContainsString('otro cliente', $ex->errors()['promo_code'][0]); }
        $this->assertSame(1, Event::where('customer_id', $maria->id)->where('starts_at', '>', now())->count(), 'a refused code books nothing');
        $s = AnniversaryCampaign::stats();
        $this->assertSame([2, 1, 50.0, 31000, 3000], [$s['issued'], $s['redeemed'], $s['rate'], $s['revenue_cents'], $s['discount_cents']]);
    }

    public function test_extra_characters_get_the_discount_and_the_travel_fee_never_does(): void
    {
        $maria = $this->client('María González');
        $this->pastParty($maria, 330);
        app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago'));
        $e = $this->book($maria, AnniversaryCode::where('customer_id', $maria->id)->value('code'), mickeys: 2);
        $rev = FinancialRevision::where('event_id', $e->id)->orderByDesc('revision')->firstOrFail();
        $this->assertSame(30000 + 15000, $rev->subtotal_cents, 'package + one extra character');
        $this->assertSame(4500, $rev->discount_cents, '10 % of package and extra character');
        $this->assertSame(45000 + 4000 - 4500, $rev->total_cents, 'travel fee in full');
    }

    public function test_an_expired_or_unknown_code_is_refused_and_no_code_books_normally(): void
    {
        $maria = $this->client('María González');
        $this->pastParty($maria, 330);
        app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago'));
        $code = AnniversaryCode::where('customer_id', $maria->id)->value('code');
        try { $this->book($maria, 'WAOO-ZZZZZ'); $this->fail('unknown'); } catch (ValidationException $ex) { $this->assertStringContainsString('no existe', $ex->errors()['promo_code'][0]); }
        $this->travel(8)->days();
        try { $this->book($maria, $code); $this->fail('expired'); } catch (ValidationException $ex) { $this->assertStringContainsString('venció', $ex->errors()['promo_code'][0]); }
        $e = $this->book($maria, null);
        $this->assertSame(0, (int) FinancialRevision::where('event_id', $e->id)->value('discount_cents'));
    }

    public function test_quick_booking_screen_previews_the_code_and_reports_show_the_effectiveness(): void
    {
        $maria = $this->client('María González');
        $this->pastParty($maria, 330);
        app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago'));
        $code = AnniversaryCode::where('customer_id', $maria->id)->value('code');
        \Livewire\Livewire::test(\App\Filament\Pages\QuickBooking::class)
            ->fillForm(['customer_mode' => 'existing', 'customer_id' => $maria->id])->fillForm(['price_version_ids' => [$this->price->id], 'travel_fee' => '40'])
            ->fillForm(['promo_code' => $code])
            ->assertSee('Código válido: 10 % menos en el paquete y los adicionales')->assertSee("con código −\$30)")
            ->assertSchemaStateSet(['deposit.amount' => '155.00']);
        \Livewire\Livewire::test(\App\Filament\Pages\QuickBooking::class)->fillForm(['promo_code' => 'WAOO-ZZZZZ'])->assertSee('Ese código no existe');
        $this->book($maria, $code);
        \Livewire\Livewire::test(\App\Filament\Pages\Reports::class)->assertSee('Un año después · efectividad')->assertSee('100 %')
            ->assertSee('1 cliente recibió su código y 1 reservó con él.');
    }

    private function fakeTransport(): object
    {
        $t = new class implements \App\Services\Mail\MailTransport {
            public array $sent = [];
            public function configured(): bool { return true; }
            public function send(#[\SensitiveParameter] \App\Services\Mail\OutboundEnvelope $m): \App\Services\Mail\TransportReceipt { $this->sent[] = $m; return new \App\Services\Mail\TransportReceipt('fake', 'm'.count($this->sent)); }
        };
        $this->app->instance(\App\Services\Mail\MailTransport::class, $t);
        return $t;
    }

    public function test_nothing_leaves_while_mail_or_the_campaign_switch_is_off_then_one_email_and_one_reminder(): void
    {
        $maria = $this->client('María González');
        $this->pastParty($maria, 330);
        app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago'));
        $t = $this->fakeTransport();
        $mail = app(\App\Services\Campaigns\AnniversaryMail::class);
        $this->assertSame(['sent' => 0, 'reminders' => 0, 'held' => 1], $mail->sendDue(), 'mail gate off');
        config(['waoo.mail_delivery_enabled' => true]);
        $this->assertSame(0, $mail->sendDue()['sent'], 'campaign switch off');
        config(['waoo.anniversary_mail_enabled' => true]);
        $this->assertSame(1, $mail->sendDue()['sent']);
        $this->assertSame(0, $mail->sendDue()['sent'], 'never twice');
        $m = $t->sent[0];
        $this->assertSame('maria@example.com', $m->recipient);
        $this->assertStringContainsString('We’d love to', $m->html);
        $this->assertStringContainsString(AnniversaryCode::sole()->code, $m->html);
        $this->assertStringContainsString('17350 State Hwy 249', $m->html, 'CAN-SPAM postal address');
        $this->assertStringContainsString('/u/promo/'.$maria->id, $m->html, 'unsubscribe link');
        $this->assertStringNotContainsString('Valentina', $m->html);
        $this->travel(6)->days();
        $this->assertSame(1, $mail->sendDue()['reminders']);
        $this->assertSame(0, $mail->sendDue()['reminders']);
        $this->assertStringContainsString('ends tomorrow', $t->sent[1]->html);
    }

    public function test_unsubscribe_link_shows_a_page_then_stops_every_promotional_email(): void
    {
        $maria = $this->client('María González');
        $this->pastParty($maria, 330);
        app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago'));
        $url = \Illuminate\Support\Facades\URL::signedRoute('promo.unsubscribe', ['customer' => $maria->id]);
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Unsubscribe from promotions?');
        $this->assertNull($maria->fresh()->promo_opt_out_at, 'opening the link (mail scanners) changes nothing');
        $this->get('/u/promo/'.$maria->id)->assertForbidden();
        $this->post($url)->assertOk()->assertSee('You’re unsubscribed');
        $this->assertNotNull($maria->fresh()->promo_opt_out_at);
        config(['waoo.mail_delivery_enabled' => true, 'waoo.anniversary_mail_enabled' => true]);
        $t = $this->fakeTransport();
        $this->assertSame(0, app(\App\Services\Campaigns\AnniversaryMail::class)->sendDue()['sent']);
        $this->assertSame([], $t->sent);
        // Next year's anchor for this client is skipped as unsubscribed.
        $this->pastParty($maria, 10);
        $this->travel(330)->days();
        app(AnniversaryCampaign::class)->issue(CarbonImmutable::now('America/Chicago'));
        $this->assertSame('unsubscribed', AnniversaryCode::where('customer_id', $maria->id)->latest('id')->value('skip_reason'));
    }

    public function test_owner_preview_renders_a_fictitious_client(): void
    {
        $this->get('/admin/anniversary/preview')->assertOk()->assertSee('WAOO-7KX9Q')->assertSee('María');
        $this->get('/admin/anniversary/preview?kind=reminder')->assertOk()->assertSee('ends tomorrow');
    }
}
