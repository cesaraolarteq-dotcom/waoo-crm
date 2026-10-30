<?php
namespace App\Console\Commands;

use App\Models\Character;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** César 2026-10-30: marks each character as head (full costume) or wig (the face shows), from his checklist. */
class SetCharacterStyles extends Command
{
    protected $signature = 'waoo:character-styles {file : JSON {"Elsa": "peluca", "Mickey": "cabeza", ...}} {--dry-run}';
    protected $description = 'Marcar personajes como cabeza o peluca (quién puede hacerlos)';
    public function handle(): int
    {
        $data = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($data) || $data === []) { $this->error('Archivo inválido.'); return self::FAILURE; }
        $map = ['cabeza' => 'head', 'peluca' => 'wig'];
        $rows = []; $unknown = [];
        foreach ($data as $name => $style) {
            $c = Character::whereRaw('lower(name) = ?', [mb_strtolower((string) $name)])->first();
            if (! $c || ! isset($map[$style])) { $unknown[] = $name; continue; }
            $rows[] = [$c, $map[$style]];
        }
        if ($unknown) { $this->error('No encontrados o tipo inválido: '.implode(', ', $unknown)); return self::FAILURE; }
        $changed = 0;
        DB::transaction(function () use ($rows, &$changed) {
            foreach ($rows as [$c, $style]) {
                if ($c->costume_style === $style) continue;
                $before = ['costume_style' => $c->costume_style];
                if (! $this->option('dry-run')) {
                    $c->forceFill(['costume_style' => $style])->save();
                    Audit::recordSystem('character.costume_style', $c, $before, ['costume_style' => $style]);
                }
                $changed++;
            }
        });
        $this->info(($this->option('dry-run') ? 'Se cambiarían: ' : 'Cambiados: ').$changed.' de '.count($rows));
        return self::SUCCESS;
    }
}
