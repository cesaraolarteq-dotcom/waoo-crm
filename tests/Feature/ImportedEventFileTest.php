<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Models\ImportRow;
use App\Services\Imports\CommitHoneyBookEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\HoneyBookFutureScenario;
use Tests\TestCase;

// Regression: the event file of an imported HoneyBook party (travel evidence without accepted_at/mode) must open.
class ImportedEventFileTest extends TestCase
{
    use DatabaseMigrations, HoneyBookFutureScenario;

    protected function setUp(): void { parent::setUp(); $this->futureScenario(); }

    public function test_imported_event_file_opens_and_says_the_travel_fee_came_from_honeybook(): void
    {
        $batch = $this->stageFuture([]); $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, $this->commitInput($batch, $row), (string) Str::uuid());
        $this->get(EventResource::getUrl('view', ['record' => $link->event_id]))->assertOk()->assertSee('importado de HoneyBook');
    }
}
