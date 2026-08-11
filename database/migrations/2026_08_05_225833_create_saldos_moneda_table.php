<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldos_moneda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moneda_id')->constrained('monedas');
            
            // Polimórfico: ¿Dónde está el dinero? (Ej. App\Models\CashSession o App\Models\CuentaBancaria)
            $table->string('ubicacion_tipo');
            $table->unsignedBigInteger('ubicacion_id');
            
            $table->decimal('saldo_actual', 15, 2)->default(0);
            
            // LA MAGIA: 6 decimales para evitar el error de redondeo prematuro
            $table->decimal('costo_promedio_ponderado', 15, 6)->default(0);
            $table->timestamp('fecha_actualizacion')->useCurrent();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saldos_moneda');
    }
};
