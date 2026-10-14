<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 - SERVICIOS CON INTELIGENCIA DE PRECIOS (octubre 2026).
 *
 *  - services: categoría (service_categories), plantilla de impuestos
 *    (tax_templates) y cleanup_minutes (buffer de limpieza separado de la
 *    duración de ejecución para optimizar la agenda).
 *  - service_variants: variantes de precio (Ej. Keratina Corto C$1,500 /
 *    Medio C$2,000 / Largo C$2,500) con duración opcional propia.
 *  - sale_details.service_variant_id: trazabilidad de qué variante se facturó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('service_category_id')
                ->nullable()
                ->constrained('service_categories')
                ->nullOnDelete();
            $table->foreignId('tax_template_id')
                ->nullable()
                ->constrained('tax_templates')
                ->nullOnDelete();
            // Tiempo de limpieza/buffer después del servicio (separado de la
            // duración de ejecución para el bloqueo de silla en la agenda).
            $table->unsignedInteger('cleanup_minutes')->default(0);
        });

        Schema::create('service_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('name', 100); // Corto / Medio / Largo / Premium...
            $table->decimal('price', 10, 2);
            // Duración en minutos si la variante cambia el tiempo de ejecución
            $table->unsignedInteger('duration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['service_id', 'sort_order']);
        });

        Schema::table('sale_details', function (Blueprint $table) {
            // nullOnDelete: las facturas históricas conservan su unit_price
            // aunque la variante se elimine del catálogo.
            $table->foreignId('service_variant_id')
                ->nullable()
                ->constrained('service_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropForeign(['service_variant_id']);
            $table->dropColumn('service_variant_id');
        });

        Schema::dropIfExists('service_variants');

        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['service_category_id']);
            $table->dropForeign(['tax_template_id']);
            $table->dropColumn(['service_category_id', 'tax_template_id', 'cleanup_minutes']);
        });
    }
};
