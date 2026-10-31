<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, Event, ImportedProjectLink, User};
use App\Services\Availability\{BindCalendarIdentity, HoneyBookCalendarFeed, WebsiteCapacity};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\TestCase;

final class CalendarIdentityTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Event $event;
    private string $feedText = '';
    private const UID = 'fictional-party-1@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-30T10:00:00-05:00'));
        config(['waoo.honeybook_ical_url' => 'https://calendar.example.test/private-feed', 'cache.default' => 'array']);
        Http::preventStrayRequests();
        Http::fake(['calendar.example.test/*' => fn () => Http::response($this->feedText)]);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->event = $this->party();
    }

    protected function tearDown(): void
    {
        DB::table('calendar_identity_links')->delete();
        parent::tearDown();
    }

    private function party(): Event
    {
        $client = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $event = Event::create(['customer_id' => $client->id, 'title' => 'Cumpleaños de Sofía',
            'starts_at' => '2026-11-21 16:00:00', 'ends_at' => '2026-11-21 17:00:00',
            'timezone' => 'America/Chicago', 'setup_minutes' => 15, 'breakdown_minutes' => 15]);
        ImportedProjectLink::create(['event_id' => $event->id, 'customer_id' => $client->id,
            'project_key' => hash('sha256', 'synthetic-'.$event->id), 'domain_sha256' => str_repeat('0', 64)]);
        return $event->fresh();
    }

    private function entry(?string $uid = self::UID, string $from = '160000', string $to = '170000'): string
    {
        return "BEGIN:VEVENT\r\n".($uid === null ? '' : "UID:{$uid}\r\n")
            ."DTSTART:20261121T{$from}Z\r\nDTEND:20261121T{$to}Z\r\nEND:VEVENT\r\n";
    }

    private function feed(string $entries): array
    {
        Cache::flush();
        $this->feedText = "BEGIN:VCALENDAR\r\n".$entries."END:VCALENDAR\r\n";
        return app(HoneyBookCalendarFeed::class)->between(CarbonImmutable::parse('2026-11-21'), CarbonImmutable::parse('2026-11-23'));
    }

    private function bind(?Event $event = null, string $uid = self::UID): int
    {
        $event ??= $this->event;
        return app(BindCalendarIdentity::class)->execute($this->owner, $event->id, [
            'uid' => $uid, 'expected_event_version' => $event->version, 'verified_same_event' => true,
            'reason' => 'Owner verified this fictional source identity against the imported agreement.',
        ]);
    }

    private function partyCount(array $entries, ?int $exclude = null): int
    {
        return (new WebsiteCapacity(CarbonImmutable::parse('2026-11-21T00:00:00-06:00'), $entries, $exclude))->partiesOn('2026-11-21');
    }

    public function test_only_verified_stable_identity_absorbs_an_imported_party_and_retries_are_idempotent(): void
    {
        $entries = $this->feed($this->entry().$this->entry('different-party@example.test'));
        $this->assertSame(3, $this->partyCount($entries), 'same timestamps are not identity');
        $id = $this->bind();
        $this->assertSame($id, $this->bind());
        $this->assertDatabaseCount('calendar_identity_links', 1);
        $this->assertSame(2, $this->partyCount($entries));
        $this->assertSame(1, $this->partyCount($entries, $this->event->id), 'reschedule excludes its own matched calendar entry');
        $entries[0][2]['scope_sha256'] = hash('sha256', 'another-feed');
        $this->assertSame(3, $this->partyCount($entries), 'a different source cannot reuse this identity');
        $stored = json_encode(DB::table('calendar_identity_links')->first());
        $this->assertStringNotContainsString(self::UID, $stored);
        $this->assertStringNotContainsString('private-feed', $stored);
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_duplicate_uid_is_deduplicated_but_conflicting_versions_are_unknown(): void
    {
        $entries = $this->feed($this->entry().$this->entry());
        $this->assertCount(1, $entries);
        $this->assertSame(hash('sha256', self::UID), $entries[0][2]['uid_sha256']);
        $this->assertStringNotContainsString(self::UID, json_encode($entries));
        $this->assertNull(HoneyBookCalendarFeed::events("BEGIN:VCALENDAR\n".$this->entry().$this->entry(from: '180000', to: '190000')."END:VCALENDAR\n"));
        $this->assertCount(2, $this->feed($this->entry(null).$this->entry(null)), 'missing UID cannot erase an entry');
    }

    public function test_changed_calendar_or_crm_times_are_not_silently_collapsed(): void
    {
        $entries = $this->feed($this->entry()); $this->bind();
        $this->assertSame(1, $this->partyCount($entries));
        $moved = $this->feed($this->entry(from: '180000', to: '190000'));
        $this->assertSame(2, $this->partyCount($moved));
        $this->event->update(['starts_at' => '2026-11-21 18:00:00', 'ends_at' => '2026-11-21 19:00:00']);
        $this->assertSame(2, $this->partyCount($moved), 'old verification cannot authorize new times');
        $this->bind($this->event->fresh());
        $this->assertSame(1, $this->partyCount($moved));
    }

    public function test_the_same_source_uid_cannot_be_bound_to_two_imported_events(): void
    {
        $this->feed($this->entry()); $this->bind();
        $other = $this->party();
        try { $this->bind($other); $this->fail('Identity reassigned.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->assertDatabaseCount('calendar_identity_links', 1);
        $this->assertSame($this->event->id, (int) DB::table('calendar_identity_links')->value('event_id'));
    }

    public function test_absent_identity_or_stale_event_version_writes_nothing(): void
    {
        $this->feed($this->entry());
        try { $this->bind(uid: 'missing@example.test'); $this->fail('Missing identity accepted.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->event->forceFill(['version' => $this->event->version + 1])->save();
        $this->event->version--;
        try { $this->bind(); $this->fail('Stale event accepted.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->assertDatabaseCount('calendar_identity_links', 0);
    }

    public function test_binding_requires_explicit_verification_and_an_active_owner_before_network_access(): void
    {
        try {
            app(BindCalendarIdentity::class)->execute($this->owner, $this->event->id, ['uid' => self::UID,
                'expected_event_version' => $this->event->version, 'verified_same_event' => false,
                'reason' => 'Identity was not verified by the owner.']);
            $this->fail('Unverified binding accepted.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('verified_same_event', $e->errors());
        }
        $this->owner->forceFill(['active' => false])->save();
        try { $this->bind(); $this->fail('Inactive owner accepted.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertDatabaseCount('calendar_identity_links', 0); }
        Http::assertNothingSent();
    }
}
