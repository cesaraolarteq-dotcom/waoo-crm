<?php
namespace Tests\Support;

use App\Services\History\HistoricalSource;
use Illuminate\Http\UploadedFile;

trait HistoricalSourceFixture
{
    private function historyRow(array $overrides = []): array
    {
        return ['project_id' => 'fictional-history-1', 'contact_id' => 'fictional-contact-1',
            'client_name' => 'María González', 'client_email' => 'history@example.test', 'client_phone' => '+12025550123',
            'event_date' => '2025-08-10', 'start_time' => '', 'end_time' => '', 'end_date' => '',
            'postal_code' => '77001', 'city' => 'Fictional city', 'project_name' => '=UNTRUSTED_SOURCE_FORMULA()',
            'child_name' => '', 'lead_source' => '', 'booked_total' => '120.00', 'currency' => 'USD',
            'project_status' => 'archived', 'archive_reason' => '', 'event_type' => '', ...$overrides];
    }

    private function historyFile(array $rows, ?array $headers = null): UploadedFile
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers ?? HistoricalSource::HEADERS, ',', '"', '');
        foreach ($rows as $row) fputcsv($stream, array_values($row), ',', '"', '');
        rewind($stream); $bytes = stream_get_contents($stream); fclose($stream);
        return UploadedFile::fake()->createWithContent('PRIVATE_FILENAME.csv', $bytes);
    }
}
