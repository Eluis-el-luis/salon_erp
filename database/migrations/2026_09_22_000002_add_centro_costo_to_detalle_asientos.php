<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agregar centro_costo_id a detalle_asientos para análisis por centro de costo.
     *
     * NOTA: la migración base create_detalle_asientos_table ya incluye esta columna.
     * Este bloque solo actúa si la columna no existe (idempotente) para no romper
     * instalaciones que ya corrieron la base.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('detalle_asientos', 'centro_costo_id')) {
            Schema::table('detalle_asientos', function (Blueprint $table) {
                $table->foreignId('centro_costo_id')->nullable()->constrained('centros_costo')->onDelete('set null')->after('cuenta_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('detalle_asientos', 'centro_costo_id')) {
            Schema::table('detalle_asientos', function (Blueprint $table) {
                $table->dropForeign(['centro_costo_id']);
                $table->dropColumn('centro_costo_id');
            });
        }
    }
};
