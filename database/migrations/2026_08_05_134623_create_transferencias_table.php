<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencias', function (Blueprint $table) {
            $table->id();
            
            // Origen de los fondos (Puede ser modelo CajaSession o CuentaBancaria)[cite: 3]
            $table->string('origen_tipo');
            $table->unsignedBigInteger('origen_id');
            
            // Destino de los fondos[cite: 3]
            $table->string('destino_tipo');
            $table->unsignedBigInteger('destino_id');
            
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->foreignId('usuario_id')->constrained('users');
            $table->enum('estado', ['completada', 'anulada'])->default('completada');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transferencias');
    }
};
