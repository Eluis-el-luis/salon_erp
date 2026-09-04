<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FormulaServicio;

class FormulaSeeder extends Seeder
{
    public function run()
    {
        // Al servicio "Alisado" (ID 2), le asignamos "Keratina" (ID 2) y le decimos que gasta 60 ml
        FormulaServicio::create([
            'service_id' => 2,
            'item_id' => 2,
            'quantity_used' => 60.00
        ]);
    }
}