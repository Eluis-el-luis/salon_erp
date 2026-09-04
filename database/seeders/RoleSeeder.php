<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rol;

class RoleSeeder extends Seeder
{
    public function run()
    {
        Rol::create(['name' => 'Administrador']);    // ID 1
        Rol::create(['name' => 'Recepción / Caja']); // ID 2
        Rol::create(['name' => 'Estilista']);        // ID 3
    }
}