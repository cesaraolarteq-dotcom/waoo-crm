<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\History\{HistoryAccess, ReadHistoricalImport, VerifyHistoricalImport};
use App\Services\Imports\{ImportInput, ImportPersistence};
use Illuminate\Console\Command;

/** Existing staged history only: never stage/import/repair rows, simulate writes, or expose source details. */
final class HistoricalImportDryRun extends Command
{
    protected $signature='waoo:history:import {--owner=} {--batch=} {--dry-run}';
    protected $description='Revisar un lote de historial existente, de solo lectura y con resultados agregados.';

    public function handle(ReadHistoricalImport $reader, VerifyHistoricalImport $verifier): int
    {
        try {
            $ownerId=filter_var($this->option('owner'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $batchId=filter_var($this->option('batch'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$this->option('dry-run') || !$ownerId || !$batchId)throw new \RuntimeException('Explicit dry run and existing batch/owner required.');
            $owner=User::findOrFail($ownerId);
            HistoryAccess::actor($owner);
            $report=ImportPersistence::transaction(function()use($owner,$batchId,$reader,$verifier){
                // Reuse the importer's existing protocol; plan and source/destination verification share one snapshot.
                ImportInput::lock();
                HistoryAccess::actor($owner);
                $view=$reader->execute($owner,$batchId);
                $verified=$verifier->execute($owner,$batchId);
                return ['schema_version'=>1,'dry_run'=>true,'domain_writes'=>false,
                    'counts'=>['source_rows'=>(int)$verified['source_rows'],'eligible'=>(int)$view['bulk']['eligible'],
                        'review_required'=>(int)$view['bulk']['review_required'],'imported'=>(int)$verified['imported'],
                        'excluded'=>(int)$verified['excluded'],'pending'=>(int)$verified['pending'],'differences'=>count($verified['differences'])],
                    'verdict'=>in_array($verified['verdict'],['copied_fields_match','pending_decisions','differences'],true)?$verified['verdict']:'differences',
                    'human_independent_reviews_complete'=>false];
            });
            $this->line(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return $report['verdict']==='copied_fields_match' && $report['counts']['review_required']===0 ? self::SUCCESS : 2;
        }catch(\Throwable){
            $this->error('No se completó la simulación. Revisa el dueño autorizado, el lote existente y el acceso de pruebas. No se modificó el historial.');
            return self::FAILURE;
        }
    }
}
