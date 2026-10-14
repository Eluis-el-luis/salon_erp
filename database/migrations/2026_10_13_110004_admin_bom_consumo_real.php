<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 - FÓRMULAS COMO BOM DINÁMICO (octubre 2026).
 *
 *  - service_formulas: receta ESTÁNDAR (BOM) enriquecida con notas y orden.
 *    Es la base para calcular márgenes ideales; NO es lo que necesariamente
 *    se consumió en cada servicio.
 *  - sale_detail_consumptions: fotografía del CONSUMO REAL por línea de venta
 *    e insumo (quantity_standard vs quantity_actual). Se captura en el POS
 *    antes de facturar y se convierte en la fuente de verdad del descuento de
 *    inventario y de su reversión exacta al anular la factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_formulas', function (Blueprint $table) {
            $table->string('notes', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::create('sale_detail_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_detail_id')->constrained('sale_details')->cascadeOnDelete();
            // restrictOnDelete: el insumo es histórico de auditoría; el catálogo
            // de artículos usa SoftDeletes, no borrado físico.
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // Cantidad según la fórmula estándar al momento de facturar (auditoría de desviación)
            $table->decimal('quantity_standard', 10, 2)->default(0);
            // Cantidad real reportada por el estilista/recepción
            $table->decimal('quantity_actual', 10, 2);
            $table->timestamps();

            $table->unique(['sale_detail_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_detail_consumptions');

        Schema::table('service_formulas', function (Blueprint $table) {
            $table->dropColumn(['notes', 'sort_order']);
        });
    }
};
