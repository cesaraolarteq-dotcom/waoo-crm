<?php

namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Customer, Event, HoneybookSyncRun, ImportBatch, ImportedProjectLink, ImportedProjectRevision, ImportRow, PriceVersion, ResourceReservation, ServiceVariant, User};
use App\Services\Scheduling\{BookingNoticePolicy, SameDayTransfer};
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner 2026-11-26: HoneyBook → CRM every 12 hours until the switch (a cloud job reads HoneyBook and posts the
 * honeybook_future_v1 file here). Safe facts are applied on their own; anything that changes the party waits for César:
 * - a NEW party with a package and characters the CRM recognizes → imported and confirmed (same rules as «Importar todo»);
 * - an imported party whose payments, signature or notes changed → payments updated (C2 money resync, never re-booked);
 * - a date, time, address, price, cancellation, unknown package or anything the CRM refuses → «Por revisar» on Inicio.
 */
final class HoneyBookSync
{
    /** Fields that define the party itself: any change here is César's decision, never automatic. */
    public const PARTY_FIELDS = ['event_date', 'start_time', 'end_time', 'event_address', 'event_zip', 'services', 'characters', 'package_total', 'travel_fee', 'discount', 'total', 'status'];

    /** HoneyBook names → catalog character names (exact names match on their own). */
    private const CHARACTER_ALIASES = ['mickey mouse' => 'Mickey', 'minnie' => 'Minnie Mouse', 'the grinch' => 'Grinch', 'ana' => 'Anna',
        'santa' => 'Santa Claus', 'cinderella' => 'Cenicienta', 'belle' => 'Bella', 'snow white' => 'Blancanieves', 'spiderman' => 'Spider-Man',
        'spider man' => 'Spider-Man', 'kpop demon hunters' => 'Rumi, Mira, Zoey', 'k-pop demon hunters' => 'Rumi, Mira, Zoey', 'kpop' => 'Rumi, Mira, Zoey'];

    private array $items = [];
    private array $counts = ['new_imported' => 0, 'payments_updated' => 0, 'unchanged' => 0, 'review' => 0];

    public function run(string $csv): HoneybookSyncRun
    {
        $actor = User::findOrFail((int) config('waoo.honeybook_sync_actor_id', 1));
        auth()->setUser($actor);
        [$headers, $rows] = $this->parse($csv);
        $rows = array_values(array_filter(array_map(fn ($r) => $this->prepare($r), $rows)));
        $run = HoneybookSyncRun::create(['status' => 'ok', 'counts' => $this->counts, 'items' => [], 'source_sha256' => hash('sha256', $csv)]);
        if ($rows === []) return $this->finish($run, null);

        $path = tempnam(sys_get_temp_dir(), 'hbsync');
        $out = fopen($path, 'w'); fputcsv($out, $headers, ',', '"', ''); foreach ($rows as $r) fputcsv($out, array_map(fn ($h) => $r[$h] ?? '', $headers), ',', '"', ''); fclose($out);
        try {
            $batch = app(StageHoneyBookFutureEvents::class)->execute($actor, new UploadedFile($path, 'honeybook-sync.csv', 'text/csv', null, true), (string) Str::uuid());
        } catch (\Throwable $e) {
            $this->review('El archivo', '', 'HoneyBook mandó un archivo que el CRM no pudo leer: '.mb_substr($e->getMessage(), 0, 160));
            return $this->finish($run, null, 'failed');
        } finally { @unlink($path); }

        foreach (ImportRow::where('import_batch_id', $batch->id)->orderBy('source_line')->get() as $row) {
            $s = $row->source; $name = (string) ($s['project_name'] ?? ''); $date = (string) ($s['event_date'] ?? '');
            if ($row->status === 'skipped') continue; // already happened or cancelled before ever being imported
            $link = $row->source_key ? ImportedProjectLink::where('project_key', $row->source_key)->first() : null;
            try {
                if (! $link) $this->importNew($actor, $batch->id, $row);
                else $this->updateMoney($actor, $batch->id, $row, $link);
            } catch (\Throwable $e) {
                $msg = $e instanceof \Illuminate\Validation\ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
                $this->review($name, $date, 'El CRM no pudo aplicarlo solo: '.mb_substr($msg, 0, 200));
            }
        }
        return $this->finish($run, $batch->id);
    }

    /** Linked projects: party fields changed → review; otherwise keep the imported wording and take only the facts. */
    private function prepare(array $r): ?array
    {
        $key = trim((string) ($r['honeybook_project_id'] ?? ''));
        $link = $key !== '' ? ImportedProjectLink::where('project_key', hash('sha256', $key))->first() : null;
        $snapshot = $link ? ImportedProjectRevision::find($link->current_revision_id)?->source_snapshot : null;
        if (! $snapshot) {
            if (($r['status'] ?? '') === 'cancelled') return null;
            if (trim($r['payments'] ?? '') !== '' || ! in_array(trim($r['amount_paid'] ?? ''), ['', '0.00'], true)
                || ! in_array(trim($r['contract_signed'] ?? ''), ['', 'no'], true)) {
                $this->review($r['project_name'], $r['event_date'], 'Vincula el proyecto antes de aplicar sus pagos o firma.');
                return null;
            }
            if (blank($r['event_address'] ?? null)) { $this->review((string) $r['project_name'], (string) $r['event_date'], 'Falta la dirección en HoneyBook (no se importa hasta tenerla; luego se suma el transporte).'); return null; }
            if (($r['characters'] ?? '') === '') $r['characters'] = $this->guessCharacters((string) ($r['project_name'] ?? ''));
            return $r;
        }
        $changed = array_values(array_filter(self::PARTY_FIELDS, fn ($f) => trim((string) ($r[$f] ?? '')) !== trim((string) ($snapshot[$f] ?? ''))));
        if ($changed !== []) {
            $labels = ['event_date' => 'fecha', 'start_time' => 'hora de inicio', 'end_time' => 'hora de fin', 'event_address' => 'dirección', 'event_zip' => 'código postal',
                'services' => 'servicios', 'characters' => 'personajes', 'package_total' => 'precio del paquete', 'travel_fee' => 'transporte', 'discount' => 'descuento', 'total' => 'total', 'status' => 'estado'];
            $detail = collect($changed)->map(fn ($f) => $labels[$f].': '.($snapshot[$f] ?? '—').' → '.($r[$f] ?? '—'))->implode(' · ');
            $this->review((string) $snapshot['project_name'], (string) $snapshot['event_date'], (($r['status'] ?? '') === 'cancelled' ? 'Cancelada en HoneyBook. ' : 'Cambió en HoneyBook: ').$detail);
            return null;
        }
        foreach (HoneyBookResyncReview::MONEY_FIELDS as $f) $snapshot[$f] = (string) ($r[$f] ?? '');
        return $snapshot;
    }

    private function importNew(User $actor, int $batchId, ImportRow $row): void
    {
        $s = $row->source; $name = (string) $s['project_name']; $date = (string) $s['event_date'];
        $codes = array_column($row->errors ?? [], 'code');
        if ($codes !== []) { $this->review($name, $date, 'Fiesta nueva en HoneyBook con datos por revisar ('.implode(', ', $codes).').'); return; }
        ['selection' => $sel, 'reason' => $why] = HoneyBookAutoMapping::guess($s);
        if ($why !== null) { $this->review($name, $date, 'Fiesta nueva en HoneyBook: '.$why); return; }
        $email = mb_strtolower(trim((string) ($row->normalized['customer_email'] ?? '')));
        $digits = substr(preg_replace('/\D/', '', (string) ($row->normalized['customer_phone'] ?? '')), -10);
        $matches = Customer::orderBy('id')->get(['id', 'email', 'phone', 'version'])->filter(fn ($c) => ($email !== '' && mb_strtolower(trim((string) $c->email)) === $email)
            || (strlen($digits) === 10 && substr(preg_replace('/\D/', '', (string) $c->phone), -10) === $digits))->values();
        if ($matches->count() > 1) { $this->review($name, $date, 'Fiesta nueva: hay varias fichas de cliente con ese correo o teléfono, elige cuál.'); return; }
        [$setup, $breakdown] = \App\Filament\Pages\ImportHoneyBook::defaultMinutes($sel['price_version_ids']);
        $services = collect($sel['price_version_ids'])->map(function ($id) use ($sel) {
            $variant = ServiceVariant::find(PriceVersion::whereKey($id)->value('service_variant_id'));
            $chars = collect($sel['characters'])->where('price_version_id', $id)->map(fn ($c) => collect($c)->except('price_version_id')->all())->values()->all();
            return ['price_version_id' => (int) $id, 'expected_variant_version' => (int) $variant->version, ...($chars !== [] ? ['characters' => $chars] : [])];
        })->all();
        $customer = $matches->first();
        $link = app(CommitHoneyBookEvent::class)->execute($actor, $batchId, $row->id, [
            'expected_batch_version' => ImportBatch::find($batchId)->version, 'expected_row_version' => $row->version, 'expected_preview_sha256' => $row->preview_sha256,
            'source_confirmed' => true, 'source_evidence_reviewed' => true, 'setup_minutes' => $setup, 'breakdown_minutes' => $breakdown, 'services' => $services, 'remember_mapping' => true,
            ...($customer ? ['customer_id' => $customer->id, 'expected_customer_version' => (int) $customer->version] : ['create_separate_customer' => true, 'new_customer_locale' => 'es'])], (string) Str::uuid());
        $this->counts['new_imported']++;
        if ($why = $this->confirm($actor, $link)) { $this->review($name, $date, 'Importada como borrador: '.$why); return; }
        $this->done('new', $name, $date, 'Fiesta nueva importada y confirmada.');
    }

    /** Confirms like «Importar todo», with the sync's reasons and real same-day drive times for shared units. */
    private function confirm(User $actor, ImportedProjectLink $link): ?string
    {
        $event = Event::find($link->event_id);
        if (! $event || $event->status !== 'draft') return null;
        $n = ImportedProjectRevision::find($link->current_revision_id)?->normalized ?? [];
        $review = ['transfers' => []];
        if (BookingNoticePolicy::requiresOwnerConfirmationReason($event->starts_at)) $review['short_notice_reason'] = 'Reservada en HoneyBook (sincronización automática).';
        if (($n['paid_cents'] ?? 0) * 2 < ($n['total_cents'] ?? 0)) $review['deposit_exception_reason'] = 'Depósito acordado y pagado en HoneyBook (sincronización automática).';
        $ids = \App\Filament\Resources\Events\Pages\ConfirmCapacity::defaultCostumes($event);
        $drive = new \App\Services\Routing\DriveMinutes(3, 4.0); $pairs = [];
        foreach ($ids as $rid) {
            $others = ResourceReservation::where('resource_id', $rid)->where('event_id', '!=', $event->id)->with('event')->get()->pluck('event')->filter();
            $before = $others->filter(fn ($o) => $o->ends_at <= $event->starts_at && SameDayTransfer::needed($o->ends_at, $event->starts_at))->sortByDesc('ends_at')->first();
            $after = $others->filter(fn ($o) => $o->starts_at >= $event->ends_at && SameDayTransfer::needed($event->ends_at, $o->starts_at))->sortBy('starts_at')->first();
            foreach ([[$before, $event], [$event, $after]] as [$f, $t]) {
                if (! $f || ! $t) continue;
                $dep = CarbonImmutable::instance($f->ends_at)->addMinutes((int) $f->breakdown_minutes);
                $min = $drive->betweenAddresses($f->address, $t->address, $dep) ?? $drive->between((string) $f->postal_code, (string) $t->postal_code, $dep);
                if ($min === null) return 'el mismo traje está en otra fiesta ese día y no se pudo calcular el traslado.';
                $pairs["{$f->id}-{$t->id}"] = ['from_event_id' => $f->id, 'to_event_id' => $t->id, 'from_version' => (int) $f->version, 'to_version' => (int) $t->version,
                    'travel_minutes' => (int) $min, 'contingency_minutes' => 30, 'departure_at' => $dep->utc()->format('Y-m-d\TH:i:sP'), 'note' => "Google Maps: {$min} min + 30 (sincronización automática)."];
            }
        }
        $review['transfers'] = array_values($pairs);
        try {
            app(ConfirmImportedCapacity::class)->execute($actor, $event->id, ['expected_version' => (int) $event->version, 'staff_later' => true, 'resource_ids' => $ids,
                'costume_outfits' => [], 'review' => $review, 'imported_revision_id' => (int) $link->current_revision_id], (string) Str::uuid());
        } catch (\Throwable $e) {
            return $e instanceof \Illuminate\Validation\ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
        }
        return null;
    }

    private function updateMoney(User $actor, int $batchId, ImportRow $row, ImportedProjectLink $link): void
    {
        $s = $row->source; $name = (string) $s['project_name']; $date = (string) $s['event_date'];
        $v = app(ReadHoneyBookProjectDiff::class)->execute($actor, $batchId, $row->id);
        if (($v['kind'] ?? null) === 'unchanged') { $this->counts['unchanged']++; return; }
        if (($v['kind'] ?? null) !== 'money_only') {
            $this->review($name, $date, 'Cambió algo que no es dinero ni firma: '.collect($v['blocked'] ?? [])->pluck('field')->unique()->implode(', ').'.');
            return;
        }
        $paid = collect($v['changes'])->firstWhere('field', 'paid_cents');
        if ($paid !== null && $paid['to'] < $paid['from']) {
            $this->review($name, $date, 'HoneyBook reportó menos dinero pagado. Revisa el saldo antes de actualizarlo.');
            return;
        }
        $c = $v['current'];
        app(CommitHoneyBookEvent::class)->execute($actor, $batchId, $row->id, [
            'expected_batch_version' => $v['batch']['version'], 'expected_row_version' => $v['row']['version'], 'expected_preview_sha256' => $v['row']['preview_sha256'],
            'resync' => ['expected_link_version' => $c['link_version'], 'expected_revision_id' => $c['revision_id'], 'expected_event_version' => $c['event_version'],
                'expected_customer_version' => $c['customer_version'], 'expected_diff_sha256' => $v['diff_sha256'], 'owner_money_reviewed' => true,
                'owner_note' => 'Sincronización automática con HoneyBook (César 2026-11-26: pagos y firmas se aplican solos).',
                ...(($v['source_difference']['can_retain'] ?? false) ? ['retain_source_difference_revision_id' => $v['source_difference']['revision_id']] : [])]], (string) Str::uuid());
        $this->counts['payments_updated']++;
        $what = collect($v['changes'] ?? [])->pluck('field')->contains('payments') ? 'Pago nuevo registrado' : 'Saldo o firma actualizados';
        $this->done('money', $name, $date, $what.' (de HoneyBook).');
    }

    private function guessCharacters(string $projectName): string
    {
        $text = ' '.mb_strtolower(preg_replace('/[^\pL\pN&\-]+/u', ' ', $projectName)).' ';
        foreach (self::CHARACTER_ALIASES as $alias => $catalog) if (str_contains($text, ' '.$alias.' ')) return $catalog;
        $found = Character::where('active', true)->orderByRaw('CHAR_LENGTH(name) DESC')->pluck('name')
            ->filter(fn ($n) => mb_strlen($n) >= 3 && str_contains($text, ' '.mb_strtolower($n).' '))->values()->all();
        return implode(', ', array_slice($found, 0, 4));
    }

    private function parse(string $csv): array
    {
        RecordWriteRejected::unless(strlen($csv) > 0 && strlen($csv) <= 1_048_576, 422, 'Archivo vacío o demasiado grande.');
        try {
            $parsed = app(ImportCsv::class)->parse($csv, 'projects', HoneyBookFutureSource::profile());
        } catch (ValidationException) {
            throw new RecordWriteRejected(422, 'HoneyBook mandó un archivo CSV inválido.');
        }
        $headers = $parsed['headers'];
        return [$headers, array_map(fn ($row) => array_combine($headers, $row['cells']), $parsed['rows'])];
    }

    private function review(string $project, string $date, string $detail): void
    {
        $this->counts['review']++;
        $this->items[] = ['kind' => 'review', 'project' => trim($project), 'date' => $date, 'detail' => $detail];
    }

    private function done(string $kind, string $project, string $date, string $detail): void
    {
        $this->items[] = ['kind' => $kind, 'project' => trim($project), 'date' => $date, 'detail' => $detail];
    }

    private function finish(HoneybookSyncRun $run, ?int $batchId, ?string $status = null): HoneybookSyncRun
    {
        $run->forceFill(['status' => $status ?? ($this->counts['review'] > 0 ? 'review' : 'ok'), 'import_batch_id' => $batchId, 'counts' => $this->counts, 'items' => $this->items])->save();
        return $run;
    }
}
