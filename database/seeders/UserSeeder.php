<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Usuario;
use App\Models\Rol;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // 1. Álvaro (Propietario) - Rol supremo con bypass global
        $rolAlvaro = Rol::where('name', 'Álvaro (Propietario)')->first();
        Usuario::create([
            'name' => 'Álvaro Rugama',
            'email' => 'admin@salon.com',
            'password' => Hash::make('admin123'),
            'role_id' => $rolAlvaro?->id,
            'role' => 'alvaro',
            'is_active' => true,
        ]);

        // 2. Administrador Operativo - Gestión operativa (inventario, RRHH, caja), SIN contabilidad
        $rolAdmin = Rol::where('name', 'Administrador')->first();
        Usuario::create([
            'name' => 'Administrador Operativo',
            'email' => 'adminops@salon.com',
            'password' => Hash::make('admin123'),
            'role_id' => $rolAdmin?->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        // 3. Contador - Auditoría financiera, acceso completo a contabilidad
        $rolContador = Rol::where('name', 'Contador')->first();
        Usuario::create([
            'name' => 'Contador del Salón',
            'email' => 'contador@salon.com',
            'password' => Hash::make('contador123'),
            'role_id' => $rolContador?->id,
            'role' => 'contador',
            'is_active' => true,
        ]);

        // 4. Recepcionista / Cajera
        $rolRecepcion = Rol::where('name', 'Recepción / Caja')->first();
        Usuario::create([
            'name' => 'Ana Recepción',
            'email' => 'caja@salon.com',
            'password' => Hash::make('caja123'),
            'role_id' => $rolRecepcion?->id,
            'role' => 'recepcion',
            'is_active' => true,
        ]);

        // 5. Estilista de Prueba
        $rolEstilista = Rol::where('name', 'Estilista')->first();
        Usuario::create([
            'name' => 'Carlos Estilista',
            'email' => 'estilista@salon.com',
            'password' => Hash::make('estilista123'),
            'role_id' => $rolEstilista?->id,
            'role' => 'estilista',
            'salario_fijo' => 5000,
            'comision_servicio' => 40,
            'is_active' => true,
        ]);
    }
}
