<?php

namespace App\Services\Reports;

use App\Models\{OwnerReportSnapshot, User};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Records\{RecordActor, RecordInput};
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PrepareOwnerReport
{
    public function execute(User $actor, array $input): OwnerReportSnapshot
    {
        Sandbox::require();
        $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['kind' => ['required', Rule::in(['weekly', 'monthly'])],
            'as_of' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01|before_or_equal:'.now()->setTimezone('America/Chicago')->toDateString()]);
        $periodKind = $data['kind'] === 'weekly' ? 'week' : 'month';
        $containing = ReportPeriod::resolve(['period' => $periodKind, 'anchor' => $data['as_of']]);
        $priorAnchor = CarbonImmutable::parse($containing['starts_on'], 'America/Chicago')->subDay()->toDateString();
        $period = ReportPeriod::resolve(['period' => $periodKind, 'anchor' => $priorAnchor]);
        $identity = ['environment' => 'test', 'kind' => $data['kind'], 'period_starts_on' => $period['starts_on']];
        if ($saved = OwnerReportSnapshot::where($identity)->first()) return $saved;

        try {
            return DB::transaction(function () use ($actor, $identity, $periodKind, $priorAnchor, $period) {
                $input = ['period' => $periodKind, 'anchor' => $priorAnchor];
                $payload = ['schema_version' => 1, 'environment' => 'test', 'timezone' => 'America/Chicago', 'due_on' => $period['ends_before'],
                    'operations' => app(OperationalReport::class)->execute($actor, $input),
                    'bookings' => app(BookingSalesReport::class)->execute($actor, $input),
                    'upcoming_weekend' => $identity['kind'] === 'weekly'
                        ? app(WeekendForecast::class)->execute($actor, ['anchor' => $period['ends_before']]) : null];
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $snapshot = OwnerReportSnapshot::create([...$identity, 'schema_version' => 1, 'period_ends_before' => $period['ends_before'],
                    'recipient' => 'sales@waooparty.com', 'payload_json' => $json, 'payload_sha256' => hash('sha256', $json), 'prepared_by' => $actor->id]);
                // Audit identifiers/hash only: do not duplicate sensitive financial payloads in logs.
                Audit::recordFor($actor, 'report.snapshot_prepared', $snapshot, [], ['kind' => $snapshot->kind,
                    'period_starts_on' => $snapshot->period_starts_on, 'period_ends_before' => $snapshot->period_ends_before, 'payload_sha256' => $snapshot->payload_sha256]);
                return $snapshot;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Outside the losing transaction, read the committed winner with a fresh DB snapshot.
            return OwnerReportSnapshot::where($identity)->first() ?? throw $e;
        }
    }
}
