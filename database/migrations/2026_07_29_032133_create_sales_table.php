<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
        $table->id();
        $table->foreignId('cashier_id')->constrained('users'); // Quien cobró
        $table->decimal('subtotal', 10, 2);
        $table->decimal('discount', 10, 2)->default(0);
        $table->decimal('total', 10, 2);
        $table->enum('currency', ['cordoba', 'dolar']);
        $table->decimal('exchange_rate', 8, 4)->default(1); // Tasa de cambio del día
        $table->enum('payment_method', ['efectivo', 'banco']); 
        
        $table->text('observations')->nullable(); // Columna de observaciónl
        $table->enum('status', ['completada', 'anulada'])->default('completada');
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
