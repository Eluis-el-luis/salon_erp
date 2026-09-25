<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PAGOS MIXTOS (Split Payments): una venta puede cobrarse con varias
     * combinaciones de método y moneda. Cada línea registra su valor en NIO.
     * El tipo 'vuelto' guarda el cambio entregado al cliente.
     */
    public function up(): void
    {
        if (!Schema::hasTable('pagos')) {
            Schema::create('pagos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('venta_id')->constrained('sales');
                $table->enum('metodo', ['efectivo', 'bac', 'lafise']);
                $table->enum('moneda', ['nio', 'usd'])->default('nio');
                $table->decimal('monto', 15, 2);
                $table->decimal('tasa', 15, 6)->default(1);
                $table->decimal('valor_nio', 15, 2);
                $table->enum('tipo', ['pago', 'vuelto'])->default('pago');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};