<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_cambio', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('moneda_origen_id')->constrained('monedas');
            $table->foreignId('moneda_destino_id')->constrained('monedas');
            // Precisión alta (6 decimales) para evitar pérdida en conversiones grandes
            $table->decimal('tasa_compra', 15, 6);
            $table->decimal('tasa_venta', 15, 6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipos_cambio');
    }
};
