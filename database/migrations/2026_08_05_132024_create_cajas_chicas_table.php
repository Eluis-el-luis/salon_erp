<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_chicas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique(); // ej. CCH-01
            $table->foreignId('responsable_id')->constrained('users'); // Quién la maneja
            $table->decimal('monto_fondo', 10, 2)->default(0); // El dinero asignado
            $table->enum('estado', ['activa', 'inactiva'])->default('activa');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cajas_chicas');
    }
};
