<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_contables', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->enum('tipo', ['activo', 'pasivo', 'patrimonio', 'ingreso', 'gasto', 'costo']);
            $table->enum('naturaleza', ['deudora', 'acreedora']);
            // Llave foránea hacia sí misma para crear jerarquías (Clase > Grupo > Cuenta)
            $table->foreignId('cuenta_padre_id')->nullable()->constrained('cuentas_contables')->onDelete('cascade');
            $table->integer('nivel')->default(1);
            $table->boolean('permite_movimiento')->default(true);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuentas_contables');
    }
};
