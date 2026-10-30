<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Event, EventCancellation, EventMessagePolicy, LedgerEntry, PriceVersion, QuickCostumeHold, RefundRequest, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Changes\{CancelEvent, RefundPayment};
use App\Services\Finance\{FinanceSummary, StripeRefunds};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class CancelAndRefundTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private PriceVersion $price; private Character $character;

    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_changes' => true]); Queue::fake(); Storage::fake('local');
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

    private function book(): Event
    {
        $start = now('America/Chicago')->addDays(20)->setTime(12, 0)->utc();
        $b = app(QuickBook::class)->execute($this->owner, ['new_customer' => ['name' => 'María González', 'phone' => '+13465550142', 'locale' => 'es'],
            'title' => 'Cumpleaños de Valentina', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHours(2)->toIso8601String(),
            'timezone' => 'America/Chicago', 'address' => '123 Avenida del Parque', 'postal_code' => '77433', 'travel_fee_cents' => 0, 'setup_minutes' => 30, 'breakdown_minutes' => 20,
            'services' => [['price_version_id' => $this->price->id, 'characters' => [['character_id' => $this->character->id, 'quantity' => 1, 'outfit' => '0']]]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 15000, 'reference' => 'MARIA-DEPOSIT-1', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]], (string) Str::uuid());
        return Event::findOrFail($b->event->id);
    }

    private function cancel(Event $e, ?string $key = null, ?int $version = null): EventCancellation
    {
        return app(CancelEvent::class)->execute($this->owner, $e->id, ['expected_version' => $version ?? $e->version, 'reason' => 'La familia cambió de planes.'], $key ?? (string) Str::uuid());
    }

    private function zelleReceipt(Event $e): LedgerEntry
    {
        return LedgerEntry::where('event_id', $e->id)->where('kind', 'receipt')->where('method', 'zelle')->firstOrFail();
    }

    private function cardReceipt(Event $e, int $cents = 20000): LedgerEntry
    {
        $z = $this->zelleReceipt($e);
        return LedgerEntry::create(['event_id' => $e->id, 'financial_revision_id' => $z->financial_revision_id, 'environment' => 'test', 'kind' => 'receipt', 'method' => 'stripe',
            'amount_cents' => $cents, 'currency' => 'USD', 'received_at' => now()->subHour(), 'external_reference' => 'pi_test_maria', 'external_identity_key' => 'stripe:test:acct_x:pi_test_maria',
            'verified_by' => null, 'source_type' => 'verified_stripe_webhook', 'source_id' => '1']);
    }

    private function fakeStripe(\Closure $answer): object
    {
        $fake = new class($answer) implements StripeRefunds {
            public array $calls = [];
            public function __construct(public \Closure $answer) {}
            public function refund(string $paymentIntentId, int $amountCents, string $key): array { $this->calls[] = [$paymentIntentId, $amountCents, $key]; return ($this->answer)($paymentIntentId, $amountCents, $key); }
        };
        $this->app->instance(StripeRefunds::class, $fake);
        return $fake;
    }

    public function test_cancel_frees_the_date_keeps_the_money_and_pauses_reminders(): void
    {
        $e = $this->book();
        $this->assertGreaterThan(0, ResourceReservation::where('event_id', $e->id)->count());
        $key = (string) Str::uuid();
        $c = $this->cancel($e, $key);

        $e->refresh();
        $this->assertSame('cancelled', $e->status);
        $this->assertSame(0, ResourceReservation::where('event_id', $e->id)->count());
        $this->assertSame('released', QuickCostumeHold::where('event_id', $e->id)->value('state'));
        $this->assertTrue(EventMessagePolicy::where('event_id', $e->id)->value('paused'));
        $this->assertSame(15000, app(FinanceSummary::class)->forEvent($e)['net_received_cents']);
        $this->assertSame(0, LedgerEntry::where('event_id', $e->id)->where('kind', 'refund')->count());
        // Same request again returns the same cancellation; a second cancel is refused.
        $this->assertSame($c->id, $this->cancel(Event::findOrFail($e->id), $key, $e->version - 1)->id);
        $this->expectException(RecordWriteRejected::class);
        $this->cancel($e);
    }

    public function test_cancel_refuses_a_stale_page_and_needs_the_switch(): void
    {
        $e = $this->book();
        try { $this->cancel($e, null, $e->version + 1); $this->fail('Stale version accepted'); } catch (RecordWriteRejected) {}
        config(['waoo.sandbox_changes' => false]);
        $this->expectException(AuthorizationException::class);
        $this->cancel($e);
    }

    public function test_zelle_refund_is_recorded_only_after_the_owner_sent_it_and_never_above_the_payment(): void
    {
        $e = $this->book(); $r = $this->zelleReceipt($e); $refund = app(RefundPayment::class);
        try { $refund->execute($this->owner, $e->id, ['receipt_id' => $r->id, 'amount_cents' => 5000, 'reason' => 'Decisión de la dueña.'], (string) Str::uuid()); $this->fail('Recorded without the bank step'); }
        catch (RecordWriteRejected $x) { $this->assertSame(422, $x->status); }
        $done = $refund->execute($this->owner, $e->id, ['receipt_id' => $r->id, 'amount_cents' => 5000, 'reason' => 'Decisión de la dueña.', 'confirm_sent' => true, 'sent_on' => now('America/Chicago')->format('Y-m-d'), 'reference' => 'ZELLE-BACK-1'], (string) Str::uuid());
        $this->assertSame('succeeded', $done->state);
        $this->assertSame(5000, app(FinanceSummary::class)->forEvent($e)['refunded_cents']);
        $this->expectException(RecordWriteRejected::class);
        $refund->execute($this->owner, $e->id, ['receipt_id' => $r->id, 'amount_cents' => 10001, 'reason' => 'Decisión de la dueña.', 'confirm_sent' => true, 'sent_on' => now('America/Chicago')->format('Y-m-d')], (string) Str::uuid());
    }

    public function test_card_refund_goes_to_stripe_once_even_when_the_first_answer_is_lost(): void
    {
        $e = $this->book(); $card = $this->cardReceipt($e); $key = (string) Str::uuid();
        $lost = true;
        $fake = $this->fakeStripe(function ($pi, $amount) use (&$lost) {
            if ($lost) { $lost = false; throw new \RuntimeException('timeout'); }
            return ['id' => 're_test_1', 'status' => 'succeeded', 'amount' => $amount, 'payment_intent' => $pi];
        });
        $input = ['receipt_id' => $card->id, 'amount_cents' => 20000, 'reason' => 'Decisión de la dueña.'];
        try { app(RefundPayment::class)->execute($this->owner, $e->id, $input, $key); $this->fail('Lost answer treated as done'); }
        catch (RecordWriteRejected $x) { $this->assertSame(503, $x->status); }
        $this->assertSame('unknown', RefundRequest::where('request_key', $key)->value('state'));
        $this->assertSame(0, LedgerEntry::where('event_id', $e->id)->where('kind', 'refund')->count());

        $done = app(RefundPayment::class)->execute($this->owner, $e->id, $input, $key);
        $this->assertSame('succeeded', $done->state);
        $this->assertSame([$key, $key], array_column($fake->calls, 2));
        $this->assertSame(['pi_test_maria', 20000], array_slice($fake->calls[1], 0, 2));
        $this->assertSame(1, LedgerEntry::where('event_id', $e->id)->where('kind', 'refund')->where('method', 'stripe')->count());
        // Repeating after success does not call Stripe again; nothing is left of that payment.
        app(RefundPayment::class)->execute($this->owner, $e->id, $input, $key);
        $this->assertCount(2, $fake->calls);
        $this->expectException(RecordWriteRejected::class);
        app(RefundPayment::class)->execute($this->owner, $e->id, ['receipt_id' => $card->id, 'amount_cents' => 1, 'reason' => 'Decisión de la dueña.'], (string) Str::uuid());
    }

    public function test_a_card_refund_stripe_rejects_frees_the_amount_again(): void
    {
        $e = $this->book(); $card = $this->cardReceipt($e);
        $this->fakeStripe(fn () => throw \Stripe\Exception\InvalidRequestException::factory('charge_already_refunded', null, null, null, null, 'charge_already_refunded'));
        try { app(RefundPayment::class)->execute($this->owner, $e->id, ['receipt_id' => $card->id, 'amount_cents' => 20000, 'reason' => 'Decisión de la dueña.'], (string) Str::uuid()); $this->fail(); }
        catch (RecordWriteRejected $x) { $this->assertSame(422, $x->status); }
        $this->assertSame('failed', RefundRequest::where('receipt_id', $card->id)->value('state'));
        $this->assertSame(0, app(FinanceSummary::class)->forEvent($e)['refunded_cents']);
    }
}
