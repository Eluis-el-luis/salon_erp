<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_asientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asiento_id')->constrained('asientos_contables')->onDelete('cascade');
            $table->foreignId('cuenta_id')->constrained('cuentas_contables');
            $table->foreignId('centro_costo_id')->nullable()->constrained('centros_costo');
            $table->foreignId('moneda_id')->constrained('monedas');
            $table->foreignId('tasa_cambio_id')->nullable()->constrained('tipos_cambio');
            
            $table->decimal('debe', 15, 2)->default(0);
            $table->decimal('haber', 15, 2)->default(0);
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detalle_asientos');
    }
};
