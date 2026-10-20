<?php

namespace App\Services\Imports;

use App\Exceptions\CutoverCohortChanged;
use App\Models\User;

final class ReadHoneyBookCutoverMembers
{
    public function execute(User $actor, array $filters = []): array
    {
        $actor = CutoverAccess::actor($actor);
        $d = ImportInput::input($filters, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50',
            'expected_cohort_sha256' => 'sometimes|required|string|regex:/^[a-f0-9]{64}$/D']);
        $perPage = (int)($d['per_page'] ?? 25);

        return app(CutoverCohort::class)->run($actor, function ($actor, $links, $events, $grants) use ($d, $perPage) {
            $snapshot = app(ReadHoneyBookCutoverReadiness::class)->snapshot($links, $events, $grants, includeRows: true);
            $view = $snapshot['view'];
            if (isset($d['expected_cohort_sha256']) && !hash_equals($view['cohort_sha256'], $d['expected_cohort_sha256'])) {
                throw new CutoverCohortChanged;
            }
            $total = count($snapshot['rows']);
            $last = max(1, (int)ceil($total / $perPage));
            $page = min((int)($d['page'] ?? 1), $last);

            return [...$view, 'page' => ['number' => $page, 'per_page' => $perPage, 'last' => $last, 'total' => $total],
                'rows' => array_slice($snapshot['rows'], ($page - 1) * $perPage, $perPage)];
        });
    }
}
