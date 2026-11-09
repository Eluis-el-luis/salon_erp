<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rol;

class RoleSeeder extends Seeder
{
    public function run()
    {
        // Rol supremo: Propietario con privilegios absolutos (bypass global en middleware)
        Rol::create(['name' => 'Álvaro (Propietario)']); // ID 1
        // Administrador operativo: gestión de inventario, RRHH, caja, NO contabilidad
        Rol::create(['name' => 'Administrador']);        // ID 2
        // Contador / Auditoría financiera: acceso completo a contabilidad, bancos, CxP
        Rol::create(['name' => 'Contador']);             // ID 3
        // Recepción / Caja: POS, ventas, arqueo, mesa de cambio, adelantos
        Rol::create(['name' => 'Recepción / Caja']);     // ID 4
        // Estilista: agenda, servicios, comisiones, portal colaborador
        Rol::create(['name' => 'Estilista']);            // ID 5
    }
}