<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Configuración esencial. Credenciales y demostraciones son pasos explícitos.
        DB::transaction(function () {
            $this->call([
                RoleSeeder::class,
                ContabilidadBaseSeeder::class,
                CuentasComplementariasSeeder::class,
                AdminCatalogoSeeder::class, // Grupos, categorías y plantillas fiscales
                RrhhBaseSeeder::class, // Ausencias, turnos y estructura salarial
            ]);
        });
    }
}
