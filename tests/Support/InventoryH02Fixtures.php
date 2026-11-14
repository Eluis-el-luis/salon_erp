<?php

namespace Tests\Support;

use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\CompraService;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\CuentasComplementariasSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

trait InventoryH02Fixtures
{
    protected function actorH02(): Usuario
    {
        $this->seed([RoleSeeder::class, ContabilidadBaseSeeder::class, CuentasComplementariasSeeder::class]);
        $actor = Usuario::create(['name' => 'H02 fixture', 'email' => bin2hex(random_bytes(8)).'@h02.example.test',
            'password' => 'fixture-password', 'pin_seguridad' => Hash::make('4826'), 'role' => 'alvaro',
            'role_id' => Rol::where('name', 'Álvaro (Propietario)')->firstOrFail()->id, 'is_active' => true]);
        SesionCaja::create(['user_id' => $actor->id, 'monto_apertura' => 0, 'estado' => 'abierta', 'fecha_apertura' => now()]);
        $this->actingAs($actor);

        return $actor;
    }

    protected function stockH02(int $units = 2, bool $fractional = false): Articulo
    {
        $item = Articulo::create(['producto' => 'H02 fixture', 'type' => 'producto_venta', 'precio_c' => 100,
            'is_fractionable' => $fractional, 'total_volume' => $fractional ? 1000 : 0, 'unit_measure' => $fractional ? 'ml' : 'und',
            'existencia_actual' => 0, 'current_volume' => 0]);
        app(CompraService::class)->registrarFactura(['provider_id' => Proveedor::create(['name' => 'H02 proveedor'])->id,
            'condicion_pago' => 'credito', 'articulos' => [['id' => $item->id, 'cantidad' => $units, 'costo' => 20]]]);

        return $item->refresh();
    }

    protected function cartH02(Articulo $item, $units = 1): array
    {
        return ['id' => $item->id, 'type' => 'producto_venta', 'precio_c' => 100, 'quantity' => $units];
    }
}
