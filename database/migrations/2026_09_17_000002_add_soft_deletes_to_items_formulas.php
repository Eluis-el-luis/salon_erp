<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MANDAMIENTO: Evitar borrado físico que rompa la trazabilidad.
     * Articulos y líneas de fórmula pasan a BAJA LÓGICA (SoftDeletes) para no
     * dejar huérfanas las ventas históricas y las recetas de servicios.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('items', 'deleted_at')) {
            Schema::table('items', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (!Schema::hasColumn('service_formulas', 'deleted_at')) {
            Schema::table('service_formulas', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('service_formulas', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};