<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos_contables', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // Ej. "Agosto 2026"
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->enum('estado', ['abierto', 'cerrado'])->default('abierto');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periodos_contables');
    }
};
