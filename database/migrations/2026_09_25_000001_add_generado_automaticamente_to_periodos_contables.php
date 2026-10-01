<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca los periodos contables creados automáticamente por el sistema cuando
     * una operación (venta, gasto, nómina, etc.) no encuentra un periodo abierto
     * que cubra su fecha. Sirve como registro/auditoría visible en /periodos.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('periodos_contables', 'generado_automaticamente')) {
            Schema::table('periodos_contables', function (Blueprint $table) {
                $table->boolean('generado_automaticamente')->default(false)->after('estado');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('periodos_contables', 'generado_automaticamente')) {
            Schema::table('periodos_contables', function (Blueprint $table) {
                $table->dropColumn('generado_automaticamente');
            });
        }
    }
};
