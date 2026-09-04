<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Servicio;

class ServiceSeeder extends Seeder
{
    public function run()
    {
        Servicio::create([
            'name' => 'Corte de Cabello de Mujer',
            'description' => 'Lavado, corte y secado básico',
            'price' => 450.00,
            'duration' => 45, 
            'is_active' => true,
        ]);

        Servicio::create([
            'name' => 'Alisado de Keratina',
            'description' => 'Alisado permanente e hidratación profunda',
            'price' => 2500.00,
            'duration' => 120, 
            'is_active' => true,
        ]);
    }
}