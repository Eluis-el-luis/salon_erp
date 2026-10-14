<?php

use Database\Seeders\AdminCatalogoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 - MÓDULO DE ADMINISTRACIÓN (octubre 2026).
 *
 * Catálogos corporativos que elevan clientes, servicios y fórmulas de CRUDs
 * planos a entidades ERP:
 *  - customer_groups: segmentación de clientes (VIP, Regular, Mayorista) con
 *    descuento automático aplicable en el POS.
 *  - service_categories: agrupación de servicios para reportes de venta.
 *  - tax_templates: plantillas de impuestos (Ej. IVA 15% / Exento) enlazables
 *    a cada servicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 255)->nullable();
            // Descuento automático en % que aplica el POS al facturar a un
            // cliente del grupo (cuando el cajero no captura un descuento manual).
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->string('color', 20)->nullable(); // Hex para badges en la interfaz
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tax_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->decimal('rate', 5, 2)->default(0); // % de impuesto
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Catálogo base idempotente (mismo patrón que CuentasComplementariasSeeder)
        (new AdminCatalogoSeeder())->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_templates');
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('customer_groups');
    }
};
