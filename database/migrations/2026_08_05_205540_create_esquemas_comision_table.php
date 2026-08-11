<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esquemas_comision', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('users')->onDelete('cascade');
            
            // Si quieres pagar distinto por cortes que por tintes, puedes usar este campo. 
            // Si es general, se deja nulo.
            $table->string('tipo_servicio')->nullable(); 
            
            $table->decimal('porcentaje_comision', 5, 2); // Ej: 30.00
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable(); // Si es nulo, es el esquema actual
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esquemas_comision');
    }
};
