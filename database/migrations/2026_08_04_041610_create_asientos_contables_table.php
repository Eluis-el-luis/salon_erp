<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asientos_contables', function (Blueprint $table) {
            $table->id();
            $table->string('numero_asiento')->unique();
            $table->date('fecha');
            $table->string('concepto');
            // Origen polimórfico: Permite añadir módulos nuevos en el futuro sin modificar el motor
            $table->string('modulo_origen')->nullable(); // Ej. 'ventas', 'nomina'
            $table->unsignedBigInteger('referencia_id')->nullable(); 
            
            $table->string('tipo_asiento')->default('diario'); // diario, ajuste, cierre
            $table->foreignId('periodo_id')->constrained('periodos_contables');
            $table->foreignId('usuario_id')->constrained('users');
            $table->enum('estado', ['borrador', 'contabilizado', 'anulado'])->default('contabilizado');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asientos_contables');
    }
};
