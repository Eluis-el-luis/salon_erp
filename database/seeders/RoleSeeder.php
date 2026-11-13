<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rol;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $names = ['Álvaro (Propietario)', 'Administrador', 'Contador', 'Recepción / Caja', 'Estilista'];
        foreach ($names as $name) {
            if (Rol::where('name', $name)->count() > 1) {
                throw new \RuntimeException('Hay roles duplicados; requieren conciliación explícita.');
            }
        }
        foreach ($names as $name) {
            Rol::firstOrCreate(['name' => $name]);
        }
    }
}
