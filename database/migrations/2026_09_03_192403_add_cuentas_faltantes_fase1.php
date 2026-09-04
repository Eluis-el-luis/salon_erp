<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Cuenta de Sueldos y Salarios (Clase 6 / Gastos Operativos)
        $gasto = DB::table('cuentas_contables')->where('codigo', '6')->first();
        if ($gasto && !DB::table('cuentas_contables')->where('codigo', '6.7')->exists()) {
            DB::table('cuentas_contables')->insert([
                'codigo' => '6.7',
                'nombre' => 'Sueldos y Salarios',
                'tipo' => 'gasto',
                'naturaleza' => 'deudora',
                'cuenta_padre_id' => $gasto->id,
                'nivel' => 2,
                'permite_movimiento' => true,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Subcuentas de Caja por divisa (Caja NIO 1.1.1.1 y Caja USD 1.1.1.2)
        $cajaGeneral = DB::table('cuentas_contables')->where('codigo', '1.1.1')->first();
        if ($cajaGeneral) {
            if (!DB::table('cuentas_contables')->where('codigo', '1.1.1.1')->exists()) {
                DB::table('cuentas_contables')->insert([
                    'codigo' => '1.1.1.1',
                    'nombre' => 'Caja Córdobas (NIO)',
                    'tipo' => 'activo',
                    'naturaleza' => 'deudora',
                    'cuenta_padre_id' => $cajaGeneral->id,
                    'nivel' => 4,
                    'permite_movimiento' => true,
                    'activa' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if (!DB::table('cuentas_contables')->where('codigo', '1.1.1.2')->exists()) {
                DB::table('cuentas_contables')->insert([
                    'codigo' => '1.1.1.2',
                    'nombre' => 'Caja Dólares (USD)',
                    'tipo' => 'activo',
                    'naturaleza' => 'deudora',
                    'cuenta_padre_id' => $cajaGeneral->id,
                    'nivel' => 4,
                    'permite_movimiento' => true,
                    'activa' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('cuentas_contables')->whereIn('codigo', ['6.7', '1.1.1.1', '1.1.1.2'])->delete();
    }
};