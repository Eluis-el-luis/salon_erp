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
        Schema::create('sale_details', function (Blueprint $table) {
        $table->id();
        $table->foreignId('sale_id')->constrained('sales')->onDelete('cascade');
        $table->foreignId('item_id')->constrained('items');
        $table->foreignId('stylist_id')->nullable()->constrained('users'); // El que hizo el servicio
        $table->integer('quantity');
        $table->decimal('unit_price', 10, 2); // Precio final cobrado
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_details');
    }
};
