<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Limpiamos las tablas dañadas
        Schema::dropIfExists('sale_details');
        Schema::dropIfExists('sales');

        // Restauramos la tabla Sales con TODOS tus campos originales + client_id
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_id')->nullable()->constrained('users');
            $table->foreignId('client_id')->nullable()->constrained('clients');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('currency')->default('cordoba');
            $table->decimal('exchange_rate', 10, 2)->default(36.80);
            $table->string('payment_method')->default('efectivo');
            $table->text('observations')->nullable();
            $table->string('status')->default('completada');
            $table->timestamps();
        });

        // Restauramos SaleDetails aceptando Productos (items) y Servicios (services)
        Schema::create('sale_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->onDelete('cascade');
            $table->foreignId('item_id')->nullable()->constrained('items');
            $table->foreignId('service_id')->nullable()->constrained('services');
            $table->foreignId('stylist_id')->nullable()->constrained('users');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sale_details');
        Schema::dropIfExists('sales');
    }
};