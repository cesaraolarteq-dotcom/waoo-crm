<?php
$root = $argv[1] ?? dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
require_once $root.'/app/Services/Availability/HoneyBookCalendarFeed.php';
use App\Services\Availability\HoneyBookCalendarFeed;
$fixtures = [
    'invalid_timed_date_nov31' => "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261131T140000Z\nDTEND:20261131T150000Z\nEND:VEVENT\nEND:VCALENDAR\n",
    'lowercase_event' => "BEGIN:VCALENDAR\nbegin:vevent\ndtstart:20261121T140000Z\ndtend:20261121T150000Z\nend:vevent\nEND:VCALENDAR\n",
    'lowercase_unsupported_recurrence' => "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261121T140000Z\nDTEND:20261121T150000Z\nrrule:FREQ=WEEKLY;COUNT=3\nEND:VEVENT\nEND:VCALENDAR\n",
];
foreach ($fixtures as $name => $ics) {
    try {
        $r = HoneyBookCalendarFeed::events($ics);
        $rendered = $r === null ? null : array_map(fn ($e) => [$e[0]->toIso8601String(), $e[1]->toIso8601String()], $r);
        echo json_encode(['case'=>$name,'actual'=>$rendered,'expected'=>'null (unknown) or supported correct full expansion'], JSON_UNESCAPED_SLASHES)."\n";
    } catch (Throwable $e) {
        echo json_encode(['case'=>$name,'exception_class'=>get_class($e)])."\n";
    }
}
