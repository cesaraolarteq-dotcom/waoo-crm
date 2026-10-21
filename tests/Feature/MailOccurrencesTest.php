<?php
namespace Tests\Feature;

use App\Models\{Customer, FinancialRevision, Lead, MailOccurrence, MailOccurrenceState, QuickBookingRecord};
use App\Services\Booking\QuickBook;
use App\Services\Documents\{ActivateTemplateVersion, CreateContract, CreateTemplateVersion, IssueContract};
use App\Services\Finance\{CreateFinancialRevision, RecordZellePayment};
use App\Services\Leads\ReceiveWebsiteLead;
use App\Services\Mail\CaptureMailOccurrence;
use App\Services\Portal\{IssuePortalGrant, SubmitZelleNotice};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue};
use Illuminate\Support\Str;
use Tests\Support\{ConcurrentWorkers, QuoteScenario};
use Tests\TestCase;

class MailOccurrencesTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;
    protected function setUp(): void
    {
        parent::setUp(); $this->scenario();
        config(['waoo.sandbox_mail' => true]); Http::preventStrayRequests(); Mail::fake();
    }
    private function payment(): \App\Models\LedgerEntry
    {
        $finance = FinancialRevision::where('event_id', $this->event->id)->first()
            ?? app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        return app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $finance->id,
            'amount_cents' => 6200, 'currency' => 'USD', 'reference' => 'SYNTHETIC-MAIL-RECEIPT', 'received_at' => now()->subMinute()->toIso8601String(),
            'verified_received' => true], (string) Str::uuid());
    }
    private function lead(?string $key = null): Lead
    {
        $secret = str_repeat('fixture-', 8); config(['waoo.sandbox_lead_intake' => true, 'waoo.lead_shared_secret' => $secret]);
        $payload = ['name' => 'Fixture Lead', 'phone' => '+13465550199', 'email' => 'lead@example.test', 'event_date' => now()->addMonth()->toDateString(),
            'start_time' => null, 'zip' => '77493', 'event_type' => 'birthday', 'package' => 'fixture', 'message' => 'PRIVATE_LEAD_NOTE',
            'travel_estimate_text' => null, 'locale' => 'es', 'consent' => null, 'demand_flag' => 'unknown', 'submitted_at' => now()->toIso8601String(), 'page' => '/contact'];
        $body = json_encode($payload); $at = (string) now()->getTimestamp(); $key ??= (string) Str::uuid();
        return app(ReceiveWebsiteLead::class)->execute($body, $at, $key, 'sha256='.hash_hmac('sha256', $at."\n".$key."\n".$body, $secret));
    }
    public function test_real_lead_hook_never_creates_fake_event_customer_finance_or_outbound(): void
    {
        $this->freezeSecond(); // the retry must sign the identical body (submitted_at/timestamp), or CI flakes across a second boundary
        $before = [DB::table('events')->count(), DB::table('customers')->count(), DB::table('financial_revisions')->count()];
        $key = (string) Str::uuid(); $lead = $this->lead($key); $this->lead($key);
        $occurrence = MailOccurrence::sole(); $this->assertSame('inquiry_received', $occurrence->key);
        $this->assertSame($lead->id, $occurrence->lead_id); $this->assertNull($occurrence->event_id); $this->assertNull($occurrence->customer_id);
        $this->assertSame($before, [DB::table('events')->count(), DB::table('customers')->count(), DB::table('financial_revisions')->count()]);
        $this->assertDatabaseCount('outbound_messages', 0); $this->assertDatabaseCount('mail_occurrence_states', 1);
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }
    public function test_real_quote_hook_freezes_original_grant_and_never_resends_on_another_preparation(): void
    {
        $key = (string) Str::uuid(); $prepared = $this->prepare($key);
        app(\App\Services\Quotes\SendQuote::class)->execute($this->owner, $this->offer->quote_id, ['expected_version' => 1, 'expected_revision' => 1], $key);
        app(\App\Services\Portal\RevokePortalGrant::class)->execute($this->owner, $prepared['portal_grant_id']);
        $this->prepare();
        $occurrence = MailOccurrence::sole(); $this->assertSame('quote_sent', $occurrence->key);
        $this->assertSame($prepared['portal_grant_id'], $occurrence->locator['portal_grant_id']);
        $this->assertSame($prepared['presentation_id'], $occurrence->locator['presentation_id']);
        $this->assertDatabaseCount('outbound_messages', 0); Mail::assertNothingSent();
    }
    public function test_verified_payment_is_separate_from_pending_client_claim_and_deduplicates(): void
    {
        $this->freezeSecond(); // both payments must carry the same received_at to be the same receipt (CI flake across a second boundary)
        $entry = $this->payment(); $this->payment();
        $this->assertSame((string) $entry->id, MailOccurrence::sole()->source_id);
        $this->assertSame('payment_received', MailOccurrence::sole()->key);
        $grant = app(IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $this->event->customer_id, 'expires_at' => now()->addDay()->toIso8601String()]);
        $context = $this->client($grant->id); $key = (string) Str::uuid();
        $input = ['financial_revision_id' => $entry->financial_revision_id, 'amount_cents' => 100, 'reference' => 'claim-only'];
        $notice = app(SubmitZelleNotice::class)->execute($context, $input, $key);
        app(SubmitZelleNotice::class)->execute($context, $input, $key);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('mail_occurrences', 1);
        $this->assertDatabaseCount('zelle_notices', 1);
        $this->assertFalse(MailOccurrence::where('key', 'zelle_notice_pending')->exists());
    }
    public function test_quick_booking_details_hook_pins_grant_and_does_not_duplicate_on_new_link(): void
    {
        $input = ['customer_id' => $this->event->customer_id, 'title' => 'Fixture quick booking', 'event_type' => 'birthday',
            'starts_at' => now()->addDays(25)->startOfHour()->toIso8601String(), 'ends_at' => now()->addDays(25)->startOfHour()->addHour()->toIso8601String(),
            'timezone' => 'America/Chicago', 'postal_code' => '77493', 'travel_fee_cents' => 0, 'setup_minutes' => 30, 'breakdown_minutes' => 20,
            'services' => [['price_version_id' => $this->price->id]], 'deposit' => ['method' => 'zelle', 'amount_cents' => 5000, 'reference' => 'SYNTHETIC-QUICK-MAIL',
                'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true], 'portal' => ['expires_at' => now()->addDays(10)->toIso8601String()]];
        $result = app(QuickBook::class)->execute($this->owner, $input, (string) Str::uuid());
        $this->assertFalse($result->link_issue_pending);
        $record = QuickBookingRecord::findOrFail($result->id);
        $occurrence = MailOccurrence::where('key', 'details_request')->sole();
        $this->assertSame($record->id, $occurrence->locator['quick_booking_id']);
        $original = $occurrence->locator['portal_grant_id'];
        app(IssuePortalGrant::class)->execute($this->owner, $result->event->id, ['customer_id' => $result->customer->id,
            'expires_at' => now()->addDays(9)->toIso8601String(), 'scopes' => ['details.submit']]);
        $this->assertSame($original, $occurrence->fresh()->locator['portal_grant_id']);
        $this->assertSame(1, MailOccurrence::where('key', 'details_request')->count());
    }
    public static function linkOrders(): array { return ['link_before_issue' => [true], 'link_after_issue' => [false]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('linkOrders')]
    public function test_contract_hook_waits_for_explicit_link_then_captures_issued_revision_once(bool $linkBefore): void
    {
        $finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $template = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Fictional isolated test only.</p>', 'change_note' => 'Fictional test template only.'], (string) Str::uuid());
        $template = app(ActivateTemplateVersion::class)->execute($this->owner, $template->id, ['expected_source_sha256' => $template->source_sha256, 'approval_note' => 'Fictional template approval in test only.']);
        $contract = app(CreateContract::class)->execute($this->owner, $this->event->id, ['expected_version' => 1, 'financial_revision_id' => $finance->id, 'template_version_id' => $template->id,
            'client' => ['first_name' => 'Fictional', 'last_name' => 'Client', 'address' => 'Fixture address', 'email' => 'client@example.test', 'phone' => '+13465550199']], (string) Str::uuid());
        $issueLink = fn () => app(IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $this->event->customer_id, 'expires_at' => now()->addDay()->toIso8601String()]);
        if ($linkBefore) $issueLink();
        app(IssueContract::class)->execute($this->owner, $contract->id, ['expected_version' => 1], (string) Str::uuid());
        if (!$linkBefore) { $this->assertDatabaseCount('mail_occurrences', 0); $issueLink(); }
        $this->assertDatabaseCount('mail_occurrences', 0);
        $this->assertSame('issued', $contract->fresh()->state);
        $this->assertDatabaseCount('signature_evidence', 0);
    }
    public function test_disabled_capture_has_no_side_effects_or_later_backfill(): void
    {
        config(['waoo.sandbox_mail' => false]); $this->payment(); $this->prepare(); $this->lead();
        $this->assertDatabaseCount('mail_occurrences', 0);
        config(['waoo.sandbox_mail' => true]);
        $this->assertDatabaseCount('mail_occurrences', 0); Mail::assertNothingSent();
    }
    public function test_capture_rolls_back_with_business_transaction_and_locator_is_encrypted_and_owner_only(): void
    {
        try { DB::transaction(function () { $this->payment(); throw new \RuntimeException('synthetic rollback'); }); }
        catch (\RuntimeException $e) { $this->assertSame('synthetic rollback', $e->getMessage()); }
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('mail_occurrences', 0);
        $this->payment(); $occurrence = MailOccurrence::sole();
        $this->assertStringNotContainsString('ledger_entry_id', DB::table('mail_occurrences')->value('locator'));
        $this->assertArrayNotHasKey('locator', $occurrence->toArray());
        $this->getJson('/admin/api/mail/occurrences?event_id='.$this->event->id)->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.locator');
        $this->owner->forceFill(['role' => 'staff'])->save();
        $this->getJson('/admin/api/mail/occurrences')->assertForbidden();
    }
    public function test_two_real_workers_capture_one_occurrence_and_one_state(): void
    {
        config(['waoo.sandbox_mail' => false]); $entry = $this->payment();
        config(['waoo.sandbox_mail' => true]);
        $this->assertDatabaseCount('mail_occurrences', 0);
        $work = fn () => DB::transaction(fn () => app(CaptureMailOccurrence::class)->record('payment_received', $entry->id,
            ['event_id' => $this->event->id, 'customer_id' => $this->event->customer_id, 'ledger_entry_id' => $entry->id], $this->owner)->id, 3);
        $results = ConcurrentWorkers::run([$work, $work]);
        $this->assertSame($results[0], $results[1]); $this->assertIsInt($results[0]);
        $this->assertDatabaseCount('mail_occurrences', 1); $this->assertDatabaseCount('mail_occurrence_states', 1);
    }
}
