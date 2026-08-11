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
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->constrained('providers')->onDelete('set null');
            
            // ¿Es un líquido que se fracciona? (ej. Keratina)
            $table->boolean('is_fractionable')->default(false); 
            
            // ¿Cuántos Mililitros (ML) o Gramos (GR) trae el envase completo?
            $table->decimal('total_volume', 8, 2)->default(0); 
            
            // ¿Cuántos ML/GR quedan actualmente en el envase abierto?
            $table->decimal('current_volume', 8, 2)->default(0); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            //
        });
    }
};
