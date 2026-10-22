<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\Finance\Sandbox;
use App\Services\History\HistoryAccess;
use App\Services\MigrationVerification\{PrivateMigrationAct,RawHoneyBookSnapshot,VerifyHoneyBookMigration as Verifier};
use Illuminate\Console\Command;

final class VerifyHoneyBookMigration extends Command
{
    protected $signature='waoo:honeybook:verify {--snapshot=} {--report=} {--owner=}';
    protected $description='Doble verificación independiente y de solo lectura; acta privada, consola agregada.';
    public function handle(RawHoneyBookSnapshot $reader,Verifier $verifier,PrivateMigrationAct $writer): int
    {
        try{
            $owner=User::findOrFail($this->option('owner'));HistoryAccess::actor($owner);Sandbox::require('sandbox_history_verification');
            $directory=realpath((string)$this->option('snapshot'));
            if(!$directory||$directory===realpath(base_path())||is_link((string)$this->option('snapshot'))||str_starts_with($directory,base_path().'/')
                ||(fileperms($directory)&0077)!==0)throw new \RuntimeException('private_snapshot_required');
            $snapshot=$reader->read($directory);
            $act=$verifier->execute($snapshot,$directory);
            $writer->write((string)$this->option('report'),$act);
            $s=$act['summary'];
            $this->info('Proyectos de origen: '.$s['source_projects'].'. Historial: '.$s['historical_projects'].'. Operativos: '.$s['operating_projects'].'. Excluidos: '.$s['excluded_projects'].'.');
            $this->info('Duplicados: '.$s['duplicate_destination_keys'].'. Extras: '.$s['extra_projects'].'. Campos coincidentes: '.$s['matched_fields'].'. Diferentes: '.$s['different_fields'].'. Sin verificar: '.$s['unverified_fields'].'.');
            $this->info('Originales: '.$s['originals']['database_count'].'. Hash interno válido: '.$s['originals']['database_hash_valid'].'. PDF de origen coincidente: '.$s['originals']['source_pdf_matched'].'.');
            $this->info('Incidencias: '.$s['issues'].'. Acta privada guardada. Resultado: '.$act['verdict'].'.');
            return $act['verdict']==='verified'?self::SUCCESS:2;
        }catch(\Throwable){
            $this->error('No se completó la verificación. Revisa permisos, formato y rutas privadas. No se muestran datos privados.');return self::FAILURE;
        }
    }
}
