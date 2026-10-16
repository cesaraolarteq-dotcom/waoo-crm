<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\Rules\Password;

class CreateOwnerAdmin extends Command
{
    protected $signature = 'waoo:admin {email} {--name=}';
    protected $description = 'Crear un administrador; contraseña oculta, alta 2FA obligatoria al entrar.';
    public function handle(): int
    {
        $data = ['email' => mb_strtolower(trim($this->argument('email'))), 'name' => $this->option('name') ?: $this->ask('Nombre'), 'password' => $this->secret('Contraseña (mínimo 12 caracteres)')];
        $validator = Validator::make($data, ['email' => 'required|email|max:255|unique:users,email', 'name' => 'required|string|max:255', 'password' => ['required', Password::min(12)->letters()->numbers()]]);
        if ($validator->fails()) { $this->error('Datos inválidos o email ya registrado. No se modificó ninguna cuenta.'); foreach ($validator->errors()->keys() as $f) $this->line(' - '.$f.': '.$validator->errors()->first($f)); $this->line(' (largo de la contraseña escrita: '.mb_strlen((string) $data['password']).')'); return self::FAILURE; }
        DB::transaction(function () use ($data) {
            $user = new User($data); $user->role = 'owner_admin'; $user->save();
            Audit::record('admin.provisioned', $user, [], ['role' => 'owner_admin', 'requires_2fa' => true]);
        });
        $this->info('Administrador creado. Debe confirmar 2FA antes de acceder a los datos.');
        return self::SUCCESS;
    }
}
