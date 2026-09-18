<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MANDAMIENTO: "Los retiros del propietario NUNCA son gastos operativos,
     * sino contra-cuentas de Patrimonio."
     *
     * 1. Se crea la subcuenta patrimonial 3.3 "Retiros del Propietario"
     *    (naturaleza deudora = contra-cuenta del Capital, acreedor).
     * 2. Se desactiva 7.2 "Gastos Personales / Retiros del Dueño" que apuntaba
     *    erróneamente a gasto, para impedir su uso futuro.
     * 3. Se crea la tabla operativa de retiros para trazabilidad.
     */
    public function up(): void
    {
        // 1. Subcuenta de Patrimonio para Retiros del Propietario
        $capital = DB::table('cuentas_contables')->where('codigo', '3')->first();
        if ($capital && !DB::table('cuentas_contables')->where('codigo', '3.3')->exists()) {
            DB::table('cuentas_contables')->insert([
                'codigo' => '3.3',
                'nombre' => 'Retiros del Propietario',
                'tipo' => 'patrimonio',
                'naturaleza' => 'deudora',
                'cuenta_padre_id' => $capital->id,
                'nivel' => 2,
                'permite_movimiento' => true,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Desactivar la antigua cuenta de gasto para retiros del dueño
        DB::table('cuentas_contables')
            ->where('codigo', '7.2')
            ->update(['activa' => false, 'permite_movimiento' => false, 'updated_at' => now()]);

        // 3. Tabla de retiros del propietario (sin borrado físico)
        if (!Schema::hasTable('retiros_propietario')) {
            Schema::create('retiros_propietario', function (Blueprint $table) {
                $table->id();
                $table->date('fecha');
                $table->decimal('monto', 15, 2);
                $table->enum('moneda', ['nio', 'usd'])->default('nio');
                $table->enum('metodo_pago', ['efectivo', 'banco'])->default('efectivo');
                $table->string('concepto', 255);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retiros_propietario');
        DB::table('cuentas_contables')->where('codigo', '3.3')->delete();
        DB::table('cuentas_contables')
            ->where('codigo', '7.2')
            ->update(['activa' => true, 'permite_movimiento' => true, 'updated_at' => now()]);
    }
};