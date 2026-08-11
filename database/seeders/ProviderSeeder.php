<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Provider;

class ProviderSeeder extends Seeder
{
    public function run()
    {
        Provider::create([
            'name' => 'L\'Oréal Professionnel',
            'contact_name' => 'Distribuidora Central',
            'phone' => '8888-0000',
            'email' => 'ventas@loreal.com'
        ]);

        Provider::create([
            'name' => 'Truss Professional',
            'contact_name' => 'Carlos López',
            'phone' => '8888-1111'
        ]);
    }
}