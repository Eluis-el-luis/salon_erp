<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('fecha_apertura')->useCurrent();
            $table->timestamp('fecha_cierre')->nullable();
            
            $table->decimal('monto_apertura', 10, 2)->default(0);
            $table->decimal('monto_teorico', 10, 2)->nullable(); // Lo que dice el sistema
            $table->decimal('monto_fisico', 10, 2)->nullable();  // Lo que contó el cajero
            $table->decimal('diferencia', 10, 2)->nullable();    // Físico - Teórico
            
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
