<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comisiones_generadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('users')->onDelete('cascade');
            
            // Conectamos la comisión con la venta exacta que la originó (usando tu tabla actual sales)
            $table->foreignId('venta_id')->constrained('sales')->onDelete('cascade');
            
            $table->decimal('monto_venta', 10, 2);
            $table->decimal('porcentaje_aplicado', 5, 2);
            $table->decimal('monto_comision', 10, 2);
            $table->date('fecha');
            
            // Se llenará cuando generemos la planilla. Por ahora es nulo.
            $table->unsignedBigInteger('periodo_nomina_id')->nullable();
            $table->enum('estado', ['pendiente', 'pagada', 'anulada'])->default('pendiente');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comisiones_generadas');
    }
};
