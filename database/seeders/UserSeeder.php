<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // 1. Administrador (Dueño)
        User::create([
            'name' => 'Álvaro Rugama',
            'email' => 'admin@salon.com',
            'password' => Hash::make('admin123'),
            'role_id' => 1,
            'role' => 'admin', 
            'is_active' => true,
        ]);

        // 2. Recepcionista / Cajera
        User::create([
            'name' => 'Ana Recepción',
            'email' => 'caja@salon.com',
            'password' => Hash::make('caja123'),
            'role_id' => 2,
            'role' => 'recepcion', 
            'is_active' => true,
        ]);

        // 3. Estilista de Prueba
        User::create([
            'name' => 'Carlos Estilista',
            'email' => 'estilista@salon.com',
            'password' => Hash::make('estilista123'),
            'role_id' => 3,
            'role' => 'estilista',
            'salario_fijo' => 5000,       // <-- Corregido para que coincida con tu tabla
            'comision_servicio' => 40,    // <-- Corregido para que coincida con tu tabla
            'is_active' => true,
        ]);
    }
}