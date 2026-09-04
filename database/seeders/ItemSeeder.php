<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Articulo;

class ItemSeeder extends Seeder
{
    public function run()
    {
        // Producto 1: Venta directa al cliente (Shampoo)
        Articulo::create([
            'codigo' => '7898625794601',
            'producto' => 'Reconstructor Capilar Deluxe Prime 260ml',
            'categoria' => 'Cabello',
            'marca' => 'Truss',
            'precio_c' => 1369.00,
            'precio_usd' => 37.00,
            'existencia_actual' => 5,
            'stock_min' => 2,
            'provider_id' => 2,
            'is_fractionable' => false,
        ]);

        // Producto 2: Uso interno fraccionable (Keratina)
        Articulo::create([
            'codigo' => 'KER-001',
            'producto' => 'Keratina Brasileña Profesional',
            'categoria' => 'Químicos',
            'marca' => 'Truss',
            'precio_c' => 3700.00,
            'precio_usd' => 100.00,
            'existencia_actual' => 3, // 3 Botellas
            'stock_min' => 1,
            'provider_id' => 2,
            'is_fractionable' => true,
            'unit_measure' => 'ml',
            'total_volume' => 1000,   // Botella de 1 Litro
            'current_volume' => 1000, 
        ]);
    }
}