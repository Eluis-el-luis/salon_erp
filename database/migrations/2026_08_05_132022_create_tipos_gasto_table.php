<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_gasto', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique(); // ej. TG-01[cite: 2]
            $table->string('nombre'); // ej. "Insumos de Cafetería", "Transporte"[cite: 2]
            
            // La magia: se conecta directo al motor contable[cite: 2]
            $table->foreignId('cuenta_contable_id')->constrained('cuentas_contables');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipos_gasto');
    }
};
