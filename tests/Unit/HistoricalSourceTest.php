<?php
namespace Tests\Unit;

use App\Services\History\HistoricalSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HistoricalSourceFixture;
use Tests\TestCase;

class HistoricalSourceTest extends TestCase
{
    use HistoricalSourceFixture;

    private function parse(array $rows): array
    {
        return app(HistoricalSource::class)->upload($this->historyFile($rows), CarbonImmutable::parse('2026-10-19T16:00:00Z'))['rows'];
    }

    public function test_date_only_nulls_original_cells_and_unknown_status_are_preserved(): void
    {
        $r = $this->parse([$this->historyRow(['booked_total' => '', 'currency' => ''])])[0];
        $this->assertNull($r['normalized']['booked_total_cents']);
        $this->assertNull($r['normalized']['currency']);
        $this->assertNull($r['normalized']['start_at']);
        $this->assertSame('date', $r['normalized']['time_precision']);
        $this->assertSame('archived_unknown', $r['normalized']['status']);
        $this->assertSame('unknown', $r['normalized']['event_type']);
        $this->assertSame('=UNTRUSTED_SOURCE_FORMULA()', $r['source']['project_name']);
        $r = $this->parse([$this->historyRow(['booked_total' => '0.00'])])[0];
        $this->assertSame(0, $r['normalized']['booked_total_cents']);
    }

    public static function issues(): array
    {
        return [
            'invalid date' => [['event_date' => '2025-02-30'], 'event_date_invalid'],
            'missing id' => [['project_id' => ''], 'project_id_required'],
            'spring gap' => [['event_date' => '2026-03-08', 'start_time' => '02:30', 'end_time' => '04:00'], 'start_time_nonexistent'],
            'fall fold' => [['event_date' => '2025-11-02', 'start_time' => '01:30', 'end_time' => '03:00'], 'start_time_ambiguous'],
            'partial time' => [['start_time' => '14:00'], 'end_time_invalid'],
            'no guessed overnight' => [['start_time' => '23:00', 'end_time' => '01:00'], 'end_not_after_start'],
            'today date only' => [['event_date' => '2026-10-19'], 'not_safely_past'],
            'overnight still running' => [['event_date' => '2026-10-18', 'end_date' => '2026-10-19', 'start_time' => '23:00', 'end_time' => '16:00'], 'not_safely_past'],
            'tomorrow' => [['event_date' => '2026-10-20'], 'not_safely_past'],
            'foreign currency' => [['currency' => 'CAD'], 'non_usd_or_unknown_currency'],
            'currency unknown' => [['currency' => ''], 'non_usd_or_unknown_currency'],
            'unparseable money' => [['booked_total' => '$120'], 'booked_total_invalid'],
            'email' => [['client_email' => 'PRIVATE_INVALID_EMAIL'], 'email_invalid'],
        ];
    }

    #[DataProvider('issues')]
    public function test_invalid_or_unresolved_source_is_flagged_without_rewriting(array $change, string $issue): void
    {
        $r = $this->parse([$this->historyRow($change)])[0];
        $this->assertContains($issue, $r['issue_codes']);
        foreach ($change as $key => $value) $this->assertSame($value, $r['source'][$key]);
    }

    public function test_explicit_overnight_and_houston_dst_offsets_are_exact(): void
    {
        $rows = $this->parse([$this->historyRow(['start_time' => '23:00', 'end_time' => '01:00', 'end_date' => '2025-08-11']),
            $this->historyRow(['project_id' => 'winter', 'event_date' => '2025-12-01', 'start_time' => '10:00', 'end_time' => '11:00'])]);
        $this->assertSame('2025-08-11T04:00:00+00:00', $rows[0]['normalized']['start_at']);
        $this->assertSame('2025-08-11T06:00:00+00:00', $rows[0]['normalized']['end_at']);
        $this->assertSame('2025-12-01T16:00:00+00:00', $rows[1]['normalized']['start_at']);
        $this->assertNotContains('end_not_after_start', $rows[0]['issue_codes']);
    }

    public function test_every_duplicate_is_marked_and_case_sensitive_identity_is_preserved(): void
    {
        $rows = $this->parse([$this->historyRow(), $this->historyRow(), $this->historyRow(['project_id' => 'FICTIONAL-HISTORY-1'])]);
        $this->assertContains('duplicate_project_id', $rows[0]['issue_codes']);
        $this->assertContains('duplicate_project_id', $rows[1]['issue_codes']);
        $this->assertNotContains('duplicate_project_id', $rows[2]['issue_codes']);
    }

    public function test_exact_headers_are_required(): void
    {
        $this->expectException(ValidationException::class);
        app(HistoricalSource::class)->upload($this->historyFile([$this->historyRow()], array_reverse(HistoricalSource::HEADERS)), CarbonImmutable::now());
    }

    public function test_invalid_utf8_and_extra_columns_fail_without_contact_values_in_errors(): void
    {
        foreach (["\xFF", implode(',', HistoricalSource::HEADERS)."\n".str_repeat('PRIVATE_CONTENT,', 20)."\n"] as $bytes) {
            try { app(HistoricalSource::class)->upload(UploadedFile::fake()->createWithContent('fixture.csv', $bytes), CarbonImmutable::now()); $this->fail('Expected invalid file.'); }
            catch (ValidationException $e) { $this->assertStringNotContainsString('PRIVATE_CONTENT', json_encode($e->errors())); }
        }
    }
    public function test_row_and_byte_limits_fail_before_persistence(): void
    {
        $files = [$this->historyFile(array_fill(0, 2001, $this->historyRow())),
            UploadedFile::fake()->createWithContent('fixture.csv', str_repeat('x', 5242881))];
        foreach ($files as $file) {
            try { app(HistoricalSource::class)->upload($file, CarbonImmutable::now()); $this->fail('Bound must be enforced.'); }
            catch (ValidationException $e) { $this->assertStringNotContainsString('PRIVATE_HISTORY_FIXTURE', json_encode($e->errors())); }
        }
    }
}
