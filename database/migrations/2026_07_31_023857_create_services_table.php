<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2); // Precio fijo o base
            $table->integer('duration')->default(30); // Duración estimada en minutos
            $table->string('description')->nullable(); // Ej: "Incluye lavado y secado"
            $table->boolean('is_active')->default(true); // Para ocultarlos sin borrarlos
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
