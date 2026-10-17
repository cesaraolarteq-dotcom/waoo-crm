<?php
namespace App\Services\Reports;

use App\Models\User;
use App\Services\Finance\Sandbox;
use App\Services\Portal\PortalContext;
use App\Services\Records\{RecordActor, RecordInput};
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

final class ReadOwnerReport
{
    public function execute(User $actor, array $input): array
    {
        Sandbox::require();
        $actor = RecordActor::authorize($actor);
        PortalContext::requireTransport(request());
        $data = RecordInput::validate($input, [
            'kind' => ['required', Rule::in(['weekly', 'monthly'])],
            'as_of' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01|before_or_equal:'.now()->setTimezone('America/Chicago')->toDateString(),
        ]);
        $kind = $data['kind'] === 'weekly' ? 'week' : 'month';
        $containing = ReportPeriod::resolve(['period' => $kind, 'anchor' => $data['as_of']]);
        $anchor = CarbonImmutable::parse($containing['starts_on'], 'America/Chicago')->subDay()->toDateString();
        $period = ReportPeriod::resolve(['period' => $kind, 'anchor' => $anchor]);
        $query = ['period' => $kind, 'anchor' => $anchor];
        return ['schema_version' => 1, 'environment' => 'test', 'timezone' => 'America/Chicago',
            'generated_at' => now()->toIso8601String(), 'kind' => $data['kind'], 'as_of' => $data['as_of'],
            'due_on' => $period['ends_before'],
            'operations' => app(OperationalReport::class)->execute($actor, $query),
            'bookings' => app(BookingSalesReport::class)->execute($actor, $query),
            'upcoming_weekend' => $data['kind'] === 'weekly' ? app(WeekendForecast::class)->execute($actor, ['anchor' => $period['ends_before']]) : null,
            'delivery' => ['recipient' => 'sales@waooparty.com', 'status' => 'not_sent', 'enabled' => false]];
    }
}
