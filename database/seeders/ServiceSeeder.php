<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Servicio;

class ServiceSeeder extends Seeder
{
    public const CORTE = 'Corte de Cabello de Mujer';
    public const ALISADO = 'Alisado de Keratina';
    public function run()
    {
        Servicio::firstOrCreate(['name' => self::CORTE], [
            'description' => 'Lavado, corte y secado básico',
            'price' => 450.00,
            'duration' => 45, 
            'is_active' => true,
        ]);

        Servicio::firstOrCreate(['name' => self::ALISADO], [
            'description' => 'Alisado permanente e hidratación profunda',
            'price' => 2500.00,
            'duration' => 120, 
            'is_active' => true,
        ]);
    }
}
