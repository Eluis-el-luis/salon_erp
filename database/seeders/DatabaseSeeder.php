<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // EL ORDEN ES ESTRICTO PARA EVITAR ERRORES DE LLAVES FORÁNEAS
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            ProviderSeeder::class, // Proveedores antes que Items
            ItemSeeder::class,
            ServiceSeeder::class,  // Servicios antes que Fórmulas
            FormulaSeeder::class,
            ContabilidadBaseSeeder::class,
        ]);
    }
}