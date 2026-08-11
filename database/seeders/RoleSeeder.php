<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        Role::create(['name' => 'Administrador']);    // ID 1
        Role::create(['name' => 'Recepción / Caja']); // ID 2
        Role::create(['name' => 'Estilista']);        // ID 3
    }
}