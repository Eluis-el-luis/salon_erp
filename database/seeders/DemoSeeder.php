<?php

namespace Database\Seeders;

use App\Models\{Empleado, Rol, Usuario};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Opcional y explícito. No crea propietario, saldos ni documentos financieros. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Los datos demostrativos solo se permiten en local/testing.');
        }
        DB::transaction(function () {
            $this->call([RoleSeeder::class, ProviderSeeder::class, ItemSeeder::class, ServiceSeeder::class, FormulaSeeder::class]);
            foreach ([
                ['admin', 'Administrador', 'adminops@salon.example.test'],
                ['contador', 'Contador', 'contador@salon.example.test'],
                ['recepcion', 'Recepción / Caja', 'caja@salon.example.test'],
                ['estilista', 'Estilista', 'estilista@salon.example.test'],
            ] as [$role, $roleName, $email]) {
                $roleId = Rol::where('name', $roleName)->sole()->id;
                $user = Usuario::firstOrCreate(['email' => $email], [
                    'name' => 'Demo '.$roleName, 'password' => bin2hex(random_bytes(32)),
                    'role' => $role, 'role_id' => $roleId, 'is_active' => true,
                ]);
                if ($user->role !== $role || $user->role_id !== $roleId) {
                    throw new \RuntimeException('Una cuenta demo existente tiene otro rol; no se modificó.');
                }
                if (! Empleado::where('user_id', $user->id)->exists()) {
                    Empleado::crearParaUsuario($user);
                }
            }
        });
    }
}
