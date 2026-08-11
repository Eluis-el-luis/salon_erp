<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banco_id')->constrained('bancos')->onDelete('cascade');
            $table->string('numero_cuenta');
            $table->string('tipo_cuenta'); // Ahorro, Corriente[cite: 3]
            
            // Asumiendo que tu tabla de monedas del motor contable se llama 'monedas'
            $table->foreignId('moneda_id')->constrained('monedas');
            
            $table->decimal('saldo_actual', 15, 2)->default(0); // Se calculará automáticamente[cite: 3]
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuentas_bancarias');
    }
};
