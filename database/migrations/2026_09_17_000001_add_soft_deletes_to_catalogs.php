<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MANDAMIENTO: Evitar el borrado físico que rompa documentos históricos.
     * Clientes y Proveedores pasan a BAJA LÓGICA (SoftDeletes): sus registros
     * siguen vinculados a ventas, citas, CxC y compras pasadas.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('clients', 'deleted_at')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (!Schema::hasColumn('providers', 'deleted_at')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};