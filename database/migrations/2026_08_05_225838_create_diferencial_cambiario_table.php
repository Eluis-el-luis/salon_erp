<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diferencial_cambiario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saldo_moneda_id')->constrained('saldos_moneda');
            $table->date('fecha');
            
            $table->decimal('monto_moneda_extranjera', 15, 2);
            $table->decimal('tasa_costo_promedio', 15, 6);
            $table->decimal('tasa_revaluacion', 15, 6); // La tasa de referencia al cierre del mes[cite: 5]
            
            $table->decimal('diferencia_calculada', 15, 2); // C$ Ganados o Perdidos[cite: 5]
            $table->enum('tipo', ['ganancia', 'perdida']);
            $table->enum('estado', ['borrador', 'contabilizado'])->default('contabilizado');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diferencial_cambiario');
    }
};
