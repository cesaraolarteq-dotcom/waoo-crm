<?php
namespace Tests\Review;

use App\Filament\Resources\Events\Pages\EventContract;
use App\Models\{ClientDetailsReview, Event, PriceVersion, User};
use App\Services\Booking\QuickBook;
use App\Services\Portal\{PortalContext, SubmitClientDetails};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;

// Opt-in review reproduction, outside the default suite. Assertions describe required behavior.
class Pr42ReviewProbeTest extends \Tests\Feature\QuickDetailsFlowTest
{
    private function fixture(): array
    {
        $owner = User::sole(); $start = now('America/Chicago')->addDays(20)->setTime(15, 0);
        $qb = app(QuickBook::class)->execute($owner, [
            'new_customer' => ['name' => 'Fixture Client', 'phone' => '555-0100', 'email' => null, 'locale' => 'es'],
            'title' => 'Fixture review party', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(),
            'timezone' => 'America/Chicago', 'postal_code' => '00001', 'travel_fee_cents' => 2500, 'services' => [['price_version_id' => PriceVersion::sole()->id]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 6250, 'reference' => 'FIXTURE-REVIEW-1', 'received_at' => now()->subHour()->toIso8601String(), 'verified_received' => true],
        ], (string) Str::uuid());
        auth()->logout(); $this->app['auth']->forgetGuards();
        $token = substr($qb->portal_url, strpos($qb->portal_url, 'token=') + 6);
        $this->postJson('/portal/api/session', ['token' => $token])->assertOk();
        $request = Request::create('/portal/api/details', 'POST');
        $request->setLaravelSession($this->app['session.store']); $request->setUserResolver(fn () => null);
        $client = PortalContext::fromRequest($request);
        $event = Event::sole();
        $input = ['expected_event_version' => $event->version, 'expected_revision' => 0,
            'first_name' => 'Fixture', 'last_name' => 'Client', 'phone' => '555-0100', 'email' => 'fixture@example.test',
            'billing_address' => 'Fixture billing', 'event_address' => 'Fixture venue ONE', 'event_postal_code' => '00001', 'start_time_confirmed' => true];
        return [$owner, $client, $event, $input];
    }
    public function test_review_approval_rejects_a_submission_changed_after_modal_opened(): void
    {
        [$owner, $client, $event, $input] = $this->fixture();
        $first = app(SubmitClientDetails::class)->execute($client, $input, (string) Str::uuid());
        $this->actingAs($owner);
        $page = Livewire::test(EventContract::class, ['record' => $event->id])->assertSee('Fixture venue ONE')->mountAction('approve')
            ->setActionData(['travel_fee_confirmed' => true, 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'review_note' => 'Owner reviewed only fixture venue ONE.']);
        $second = app(SubmitClientDetails::class)->execute($client, [...$input, 'expected_revision' => 1, 'event_address' => 'Fixture venue TWO far away', 'event_postal_code' => '99999'], (string) Str::uuid());
        $page->callMountedAction();
        $review = ClientDetailsReview::first();
        $this->assertSame(0, ClientDetailsReview::count(), 'Stale modal must not approve unseen submission. Actual reviewed submission='.(string) $review?->submission_id.'; first='.$first->id.'; second='.$second->id.'; saved venue='.$event->fresh()->address);
    }
    public function test_review_portal_displays_the_approved_sms_text_it_will_record(): void
    {
        [$owner, $client, $event, $input] = $this->fixture();
        config(['waoo.sms_consent_versions' => ['fixture-approved-v2' => 'Fixture approved SMS wording must be shown exactly.']]);
        $this->get('/portal')->assertOk()->assertSee('name="sms_consent"', false)->assertSee('Fixture approved SMS wording must be shown exactly.');
    }
}
