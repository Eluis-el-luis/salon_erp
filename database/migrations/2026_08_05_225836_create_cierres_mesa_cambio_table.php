<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierres_mesa_cambio', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('moneda_id')->constrained('monedas');
            
            $table->decimal('saldo_inicial', 15, 2);
            $table->decimal('total_compras', 15, 2);
            $table->decimal('total_ventas', 15, 2);
            $table->decimal('saldo_final_teorico', 15, 2);
            $table->decimal('saldo_final_contado', 15, 2);
            $table->decimal('diferencia', 15, 2);
            
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cierres_mesa_cambio');
    }
};
