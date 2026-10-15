<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * METAS DINÁMICAS DEL DASHBOARD (BI): reemplaza el valor hardcodeado de
 * config('salon.meta_mensual'). Cada fila es la meta de facturación de UN mes
 * (periodo = primer día del mes). El dashboard lee la meta del mes en curso y,
 * si no existe, hereda la última meta conocida (ver CompanyTarget::montoParaMes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_targets', function (Blueprint $table) {
            $table->id();
            // Primer día del mes al que aplica la meta (unique: una meta por mes)
            $table->date('periodo')->unique();
            // Meta de facturación en moneda base (NIO)
            $table->decimal('monto', 15, 2);
            $table->string('moneda', 3)->default('NIO');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_targets');
    }
};
