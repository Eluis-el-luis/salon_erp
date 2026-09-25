<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FASE 5 - NÓMINA:
     * - Columnas de deducciones de ley en planillas.
     * - Pasivo "Retenciones por Pagar" (2.1.5) para INSS/IR.
     */
    public function up(): void
    {
        // Pasivo de retenciones por pagar (INSS/IR)
        $pasivoCorriente = DB::table('cuentas_contables')->where('codigo', '2.1')->first();
        if ($pasivoCorriente && !DB::table('cuentas_contables')->where('codigo', '2.1.5')->exists()) {
            DB::table('cuentas_contables')->insert([
                'codigo' => '2.1.5',
                'nombre' => 'Retenciones por Pagar (INSS/IR)',
                'tipo' => 'pasivo',
                'naturaleza' => 'acreedora',
                'cuenta_padre_id' => $pasivoCorriente->id,
                'nivel' => 3,
                'permite_movimiento' => true,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!Schema::hasColumn('payrolls', 'inss_empleado')) {
            Schema::table('payrolls', function (Blueprint $table) {
                $table->decimal('inss_empleado', 10, 2)->default(0)->after('loan_payments');
                $table->decimal('impuesto_renta', 10, 2)->default(0)->after('inss_empleado');
                $table->decimal('retenciones_totales', 10, 2)->default(0)->after('impuesto_renta');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['inss_empleado', 'impuesto_renta', 'retenciones_totales']);
        });
        DB::table('cuentas_contables')->where('codigo', '2.1.5')->delete();
    }
};