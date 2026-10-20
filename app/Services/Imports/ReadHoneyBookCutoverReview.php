<?php
namespace App\Services\Imports;
use App\Models\User;
final class ReadHoneyBookCutoverReview
{
    public function execute(User $actor, int $eventId): array
    {
        return app(CutoverCohort::class)->run($actor, function ($actor, $links, $events, $grants) use ($eventId) {
            $link = $links->firstWhere('event_id', $eventId); abort_unless($link, 404);
            return app(CutoverMember::class)->inspect($link, $events->get($eventId), $grants)['view'];
        });
    }
}
