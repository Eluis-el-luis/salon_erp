<?php

namespace App\Console\Commands;

use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;

class ConfigureOwner extends Command
{
    protected $signature = 'salon:propietario';
    protected $description = 'Crear el propietario local mediante entrada oculta, sin credenciales por defecto';

    public function handle(): int
    {
        if (! $this->laravel->environment('local', 'testing') || ! $this->input->isInteractive()) {
            $this->error('Requiere entorno local e interacción protegida.');
            return self::FAILURE;
        }
        $password = $pin = null;
        try {
            $name = $this->ask('Nombre del propietario');
            $email = $this->ask('Correo del propietario', 'admin@salon.com');
            // false: si no hay terminal que oculte la entrada, no permitir eco.
            $password = $this->secret('Contraseña (mínimo 12 caracteres)', false);
            if ($password !== $this->secret('Confirmar contraseña', false)) {
                $this->error('Las contraseñas no coinciden.');
                return self::FAILURE;
            }
            $pin = $this->secret('PIN de supervisor (4 a 6 dígitos)', false);
            if ($pin !== $this->secret('Confirmar PIN', false)) {
                $this->error('Los PIN no coinciden.');
                return self::FAILURE;
            }
            config(['salon.bootstrap_owner' => compact('name', 'email', 'password', 'pin')]);
            app(UserSeeder::class)->run();
            $this->info('Propietario verificado. Una cuenta existente conserva sus credenciales.');
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('No se pudo configurar el propietario. Revise requisitos y cuenta existente sin publicar credenciales.');
            // Solo metadatos: el mensaje y la traza pueden contener credenciales.
            $this->error(sprintf('Diagnóstico local: %s en %s:%d.', get_class($error), basename($error->getFile()), $error->getLine()));
            return self::FAILURE;
        } finally {
            config(['salon.bootstrap_owner' => null]);
            unset($password, $pin);
        }
    }
}
