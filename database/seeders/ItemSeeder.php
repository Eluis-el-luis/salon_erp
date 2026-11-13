<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Articulo;
use App\Models\Proveedor;

class ItemSeeder extends Seeder
{
    public function run()
    {
        $provider = Proveedor::where('name', 'Truss Professional')->sole();
        // Producto 1: Venta directa al cliente (Shampoo)
        Articulo::withTrashed()->firstOrCreate(['codigo' => '7898625794601'], [
            'type' => 'producto_venta',
            'producto' => 'Reconstructor Capilar Deluxe Prime 260ml',
            'categoria' => 'Cabello',
            'marca' => 'Truss',
            'precio_c' => 1369.00,
            'precio_usd' => 37.00,
            'existencia_actual' => 0,
            'stock_min' => 2,
            'provider_id' => $provider->id,
            'is_fractionable' => false,
            'unit_measure' => 'und',
            'total_volume' => 0,
            'current_volume' => 0,
        ]);

        // Producto 2: Uso interno fraccionable (Keratina)
        Articulo::withTrashed()->firstOrCreate(['codigo' => 'KER-001'], [
            'type' => 'producto_interno',
            'producto' => 'Keratina Brasileña Profesional',
            'categoria' => 'Químicos',
            'marca' => 'Truss',
            'precio_c' => 3700.00,
            'precio_usd' => 100.00,
            'existencia_actual' => 0,
            'stock_min' => 1,
            'provider_id' => $provider->id,
            'is_fractionable' => true,
            'unit_measure' => 'ml',
            'total_volume' => 1000,   // Botella de 1 Litro
            'current_volume' => 0,
        ]);
    }
}
