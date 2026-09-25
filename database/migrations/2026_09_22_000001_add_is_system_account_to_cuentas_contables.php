<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agregar campo is_system_account para proteger cuentas críticas del sistema.
     * Las cuentas del sistema no se pueden borrar ni cambiar de código, solo nombre.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('cuentas_contables', 'is_system_account')) {
            Schema::table('cuentas_contables', function (Blueprint $table) {
                $table->boolean('is_system_account')->default(false)->after('activa');
            });
        }

        // El catálogo inicial (seed) es la base sobre la que trabaja el motor de
        // partida doble (ContabilidadService resuelve cuentas por código). Se marca
        // como protegido para impedir que se borre o se le cambie el código.
        // Las cuentas nuevas creadas desde la UI nacen con is_system_account = false.
        DB::table('cuentas_contables')->update(['is_system_account' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('cuentas_contables', 'is_system_account')) {
            Schema::table('cuentas_contables', function (Blueprint $table) {
                $table->dropColumn('is_system_account');
            });
        }
    }
};
