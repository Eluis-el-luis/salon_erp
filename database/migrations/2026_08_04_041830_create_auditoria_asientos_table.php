<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_asientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asiento_id')->constrained('asientos_contables')->onDelete('cascade');
            $table->foreignId('usuario_id')->constrained('users'); // Quién hizo el cambio[cite: 3]
            $table->string('accion'); // 'creacion', 'reversion', 'anulacion'[cite: 3]
            $table->timestamp('fecha')->useCurrent(); // Cuándo se hizo[cite: 3]
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditoria_asientos');
    }
};
