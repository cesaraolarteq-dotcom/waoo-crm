<?php
namespace App\Console\Commands;
use App\Models\User;
use App\Services\HoneyBook\ImportSupplement;
use Illuminate\Console\Command;
final class ImportHoneyBookSupplement extends Command
{
    protected $signature = 'waoo:honeybook:supplement {file} {--owner=}';
    protected $description = 'Conservar información adicional privada de HoneyBook sin efectos operativos.';
    public function handle(ImportSupplement $service): int {
        try {
            $owner = User::findOrFail($this->option('owner'));
            \App\Services\HoneyBook\SupplementAccess::check($owner);
            $file = $this->argument('file');
            if (str_contains($file, '://') || ! is_file($file) || ! is_readable($file) || filesize($file) > 2097152) throw new \RuntimeException();
            $json = file_get_contents($file, false, null, 0, 2097153);
            $result = $service->execute($owner, $json);
            $this->info('Conservados: '.$result['created'].'. Sin cambios: '.$result['unchanged'].'.');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('No se importó el archivo. Revisa acceso, perfil y límites; no se muestran datos privados.');
            return self::FAILURE;
        }
    }
}
