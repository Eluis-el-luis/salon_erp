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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            
            // Datos de Identificación y Ubicación
            $table->string('codigo')->nullable()->unique(); 
            $table->string('ubicacion')->nullable(); 
            $table->string('producto'); 
            
            // Clasificación
            $table->string('categoria')->nullable(); 
            $table->string('marca')->nullable(); 
            $table->enum('type', ['servicio', 'producto_venta', 'producto_interno']);
            
            // Precios Bimonetarios
            $table->decimal('precio_c', 10, 2)->default(0);
            $table->decimal('precio_usd', 10, 2)->default(0); 
            
            // Control de Stock
            $table->integer('existencia_actual')->default(0); 
            $table->integer('stock_min')->default(5); 
            
            // El STATUS (ej. "MINIMO") no se guarda en BD, se calcula dinámicamente en la vista
            // comparando si existencia_actual <= stock_min

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
