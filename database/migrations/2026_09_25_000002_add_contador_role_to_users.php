<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El módulo permite crear colaboradores con rol "Contador", pero el enum
     * original de users.role solo tenía admin/estilista/recepcion. Esto provocaba
     * "Data truncated for column 'role'" al guardar un contador.
     */
    public function up(): void
    {
        // 1. Ampliar el enum de users.role
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','estilista','recepcion','contador') NOT NULL DEFAULT 'estilista'");

        // 2. Asegurar el rol en la tabla roles (usada por role_id, FK obligatoria)
        if (Schema::hasTable('roles') && !DB::table('roles')->where('name', 'Contador')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Contador',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','estilista','recepcion') NOT NULL DEFAULT 'estilista'");
    }
};
