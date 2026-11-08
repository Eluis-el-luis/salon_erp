<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GOBERNANZA Y SEGURIDAD (octubre 2026):
     *
     * 1. PIN DE SUPERVISOR (doble confirmación): users.pin_seguridad guarda el
     *    PIN (4-6 dígitos, SIEMPRE hasheado con Hash::make()) que la
     *    administración define al crear o editar el perfil. Se exige para
     *    acciones críticas: abrir/cerrar arqueo, salidas de caja chica,
     *    anulaciones y aprobaciones de transacciones retenidas.
     *
     * 2. ROL SUPREMO "ÁLVARO": propietario con privilegios absolutos (bypass
     *    global en RoleMiddleware). El enum de users.role se amplía y el
     *    usuario propietario (admin@salon.com) migra a 'alvaro'.
     *
     * 3. FLUJO DE PRE-APROBACIÓN: los estados 'pendiente_aprobacion' retienen
     *    transacciones sensibles SIN contabilizar ni mover inventario. Campos
     *    de auditoría (aprobada_por / aprobada_at) y el payload del libro de
     *    pagos (sales.payload_aprobacion) permiten ejecutar el asiento al
     *    aprobar.
     */
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. PIN DE SUPERVISOR
        // ---------------------------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'pin_seguridad')) {
                $table->string('pin_seguridad')->nullable()->after('password');
            }
        });

        // ---------------------------------------------------------------
        // 2. ROL SUPREMO 'ALVARO'
        // ---------------------------------------------------------------
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('alvaro','admin','estilista','recepcion','contador') NOT NULL DEFAULT 'estilista'");

        $rolAlvaroId = null;
        if (Schema::hasTable('roles')) {
            $existe = DB::table('roles')->where('name', 'Álvaro (Propietario)')->first();
            if ($existe) {
                $rolAlvaroId = $existe->id;
            } else {
                $rolAlvaroId = DB::table('roles')->insertGetId([
                    'name' => 'Álvaro (Propietario)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // El propietario del sistema pasa a 'alvaro' (privilegios absolutos)
            if ($rolAlvaroId) {
                DB::table('users')
                    ->where('email', 'admin@salon.com')
                    ->where('role', 'admin')
                    ->update(['role' => 'alvaro', 'role_id' => $rolAlvaroId]);
            }
        }

        // ---------------------------------------------------------------
        // 3. ESTADO DE PRE-APROBACIÓN EN VENTAS
        // ---------------------------------------------------------------
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'aprobada_at')) {
                $table->timestamp('aprobada_at')->nullable()->after('motivo_anulacion');
            }
            if (!Schema::hasColumn('sales', 'aprobada_por')) {
                $table->foreignId('aprobada_por')->nullable()->after('aprobada_at')->constrained('users');
            }
            if (!Schema::hasColumn('sales', 'payload_aprobacion')) {
                // Libro de pagos original (JSON) que se ejecuta al aprobar
                $table->json('payload_aprobacion')->nullable()->after('aprobada_por');
            }
        });

        // ---------------------------------------------------------------
        // 4. ESTADO DE PRE-APROBACIÓN EN COMPRAS
        // ---------------------------------------------------------------
        DB::statement("ALTER TABLE purchase_invoices MODIFY estado ENUM('pendiente_aprobacion','registrada','anulada') NOT NULL DEFAULT 'registrada'");

        Schema::table('purchase_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_invoices', 'aprobada_at')) {
                $table->timestamp('aprobada_at')->nullable()->after('estado');
            }
            if (!Schema::hasColumn('purchase_invoices', 'aprobada_por')) {
                $table->foreignId('aprobada_por')->nullable()->after('aprobada_at')->constrained('users');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_invoices', 'aprobada_por')) {
                $table->dropConstrainedForeignId('aprobada_por');
            }
            if (Schema::hasColumn('purchase_invoices', 'aprobada_at')) {
                $table->dropColumn('aprobada_at');
            }
        });

        DB::table('purchase_invoices')->where('estado', 'pendiente_aprobacion')->update(['estado' => 'registrada']);
        DB::statement("ALTER TABLE purchase_invoices MODIFY estado ENUM('registrada','anulada') NOT NULL DEFAULT 'registrada'");

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'payload_aprobacion')) {
                $table->dropColumn('payload_aprobacion');
            }
            if (Schema::hasColumn('sales', 'aprobada_por')) {
                $table->dropConstrainedForeignId('aprobada_por');
            }
            if (Schema::hasColumn('sales', 'aprobada_at')) {
                $table->dropColumn('aprobada_at');
            }
        });

        // El propietario vuelve a 'admin' antes de revertir el enum
        if (Schema::hasTable('roles')) {
            $rolAdminId = DB::table('roles')->where('name', 'Administrador')->value('id');
            DB::table('users')
                ->where('role', 'alvaro')
                ->update(['role' => 'admin', 'role_id' => $rolAdminId]);
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','estilista','recepcion','contador') NOT NULL DEFAULT 'estilista'");

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'pin_seguridad')) {
                $table->dropColumn('pin_seguridad');
            }
        });
    }
};
