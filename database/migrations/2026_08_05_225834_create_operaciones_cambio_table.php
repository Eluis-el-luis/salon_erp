<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operaciones_cambio', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->enum('tipo', ['compra', 'venta']); // Si tú compras los USD al cliente o le vendes[cite: 5]
            
            $table->foreignId('moneda_origen_id')->constrained('monedas');
            $table->foreignId('moneda_destino_id')->constrained('monedas');
            
            $table->decimal('monto_origen', 15, 2);
            $table->decimal('monto_destino', 15, 2);
            $table->decimal('tasa_aplicada', 15, 6); // Ej: 36.666667[cite: 5]
            
            // (Opcional) Si llevas un catálogo de tasas oficiales diarias
            $table->unsignedBigInteger('tipo_cambio_id')->nullable();
            
            // Polimórfico: De dónde salió la plata o a dónde entró[cite: 5]
            $table->string('origen_pago_tipo');
            $table->unsignedBigInteger('origen_pago_id');
            
            $table->foreignId('cliente_id')->nullable()->constrained('clients');
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
        Schema::dropIfExists('operaciones_cambio');
    }
};
