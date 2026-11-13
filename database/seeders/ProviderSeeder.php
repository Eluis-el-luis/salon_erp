<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Proveedor;

class ProviderSeeder extends Seeder
{
    public function run()
    {
        Proveedor::withTrashed()->firstOrCreate(['name' => 'L\'Oréal Professionnel'], [
            'contact_name' => 'Distribuidora Central',
            'phone' => '8888-0000',
            'email' => 'ventas@loreal.com'
        ]);

        Proveedor::withTrashed()->firstOrCreate(['name' => 'Truss Professional'], [
            'contact_name' => 'Carlos López',
            'phone' => '8888-1111'
        ]);
    }
}
