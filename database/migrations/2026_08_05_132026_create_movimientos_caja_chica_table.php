<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_caja_chica', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_chica_id')->constrained('cajas_chicas')->onDelete('cascade');
            $table->foreignId('tipo_gasto_id')->constrained('tipos_gasto');
            
            $table->string('descripcion'); // Qué se compró exactamente
            $table->decimal('monto', 10, 2);
            $table->date('fecha');
            $table->string('comprobante_url')->nullable(); // Foto del ticket o factura
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja_chica');
    }
};
