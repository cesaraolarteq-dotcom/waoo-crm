<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\HoneyBookCopies;
use Illuminate\Support\Facades\Storage;

final class HoneyBookCopyController extends Controller
{
    /** Owner-only: the HoneyBook record copy of this event (private file, not cached). */
    public function __invoke(int $event)
    {
        $copy = HoneyBookCopies::forEvent($event);
        abort_if($copy === null, 404);
        return response()->file(Storage::disk('local')->path($copy['path']), ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="honeybook-copy-event-'.$event.'.pdf"', 'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']);
    }
}
