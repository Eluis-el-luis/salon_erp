<?php

namespace Database\Seeders;

use App\Models\{Empleado, Rol, Usuario};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash, Validator};

/** Propietario local: configuración solo en memoria desde salon:propietario. */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $data = config('salon.bootstrap_owner');
        if (! is_array($data) || Validator::make($data, [
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255',
            'password' => 'required|string|min:12', 'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ])->fails()) {
            throw new \RuntimeException('Configure el propietario mediante salon:propietario; no hay credenciales predeterminadas.');
        }
        DB::transaction(function () use ($data) {
            $this->call(RoleSeeder::class);
            $role = Rol::where('name', 'Álvaro (Propietario)')->sole();
            $owner = Usuario::firstOrCreate(['email' => $data['email']], [
                'name' => $data['name'], 'password' => Hash::make($data['password']),
                'pin_seguridad' => Hash::make($data['pin']),
                'role' => 'alvaro', 'role_id' => $role->id, 'is_active' => true,
            ]);
            if ($owner->role !== 'alvaro' || $owner->role_id !== $role->id || ! $owner->is_active) {
                throw new \RuntimeException('La cuenta existente no es un propietario activo coherente; no se modificó.');
            }
            if (! is_string($owner->pin_seguridad) || ! Hash::isHashed($owner->pin_seguridad)) {
                throw new \RuntimeException('La cuenta existente requiere configuración explícita de PIN; no se modificó.');
            }
            // No cambiar contraseña, PIN, contrato ni salario al repetir el seeder.
            if (! Empleado::where('user_id', $owner->id)->exists()) {
                Empleado::crearParaUsuario($owner);
            }
        });
    }
}
