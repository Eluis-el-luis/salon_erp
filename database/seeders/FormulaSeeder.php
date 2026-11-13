<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FormulaServicio;
use App\Models\Articulo;
use App\Models\Servicio;

class FormulaSeeder extends Seeder
{
    public function run()
    {
        $service = Servicio::where('name', ServiceSeeder::ALISADO)->sole();
        $item = Articulo::where('codigo', 'KER-001')->sole();
        // Identidades explícitas de estos fixtures dentro de SUS catálogos.
        FormulaServicio::withTrashed()->firstOrCreate([
            'service_id' => $service->id,
            'item_id' => $item->id,
        ], [
            'quantity_used' => 60.00
        ]);
    }
}
