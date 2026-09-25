<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MANDAMIENTO DE MERMAS:
     * Registra salidas de inventario por daño, vencimiento o derrame. Genera un
     * asiento que debita "Gastos por Merma" (6.8) y acredita Inventario, sacando
     * físicamente el producto para que el arqueo cuadre.
     */
    public function up(): void
    {
        // Subcuenta de gasto por mermas bajo Clase 6 (Gastos Operativos)
        $gasto = DB::table('cuentas_contables')->where('codigo', '6')->first();
        if ($gasto && !DB::table('cuentas_contables')->where('codigo', '6.8')->exists()) {
            DB::table('cuentas_contables')->insert([
                'codigo' => '6.8',
                'nombre' => 'Mermas y Desperdicios de Inventario',
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

        if (!Schema::hasTable('mermas')) {
            Schema::create('mermas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_id')->constrained('items');
                $table->date('fecha');
                $table->enum('tipo', ['unidad', 'volumen'])->default('unidad');
                $table->decimal('cantidad', 12, 4);
                $table->decimal('valor', 15, 2)->default(0);
                $table->string('motivo', 255);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mermas');
        DB::table('cuentas_contables')->where('codigo', '6.8')->delete();
    }
};