<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CICLO DE VIDA DEL DOCUMENTO (estados y anulaciones):
     * - sales.status pasa a un workflow: borrador | emitida | pagada | anulada.
     *   El histórico 'completada' se renombra a 'pagada' (su significado real).
     * - Campos de auditoría de anulación: quién, cuándo y por qué. El documento
     *   NUNCA se borra físicamente; se anula y se revierte su efecto.
     * - appointment_id: trazabilidad de la cita facturada (permite cerrar la cita
     *   cuando un borrador asociado a una cita se convierte en venta cobrada).
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'appointment_id')) {
                $table->foreignId('appointment_id')->nullable()->after('client_id')->constrained('appointments');
            }
            if (!Schema::hasColumn('sales', 'anulada_at')) {
                $table->timestamp('anulada_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('sales', 'anulada_por')) {
                $table->foreignId('anulada_por')->nullable()->after('anulada_at')->constrained('users');
            }
            if (!Schema::hasColumn('sales', 'motivo_anulacion')) {
                $table->string('motivo_anulacion')->nullable()->after('anulada_por');
            }
        });

        // Renombrar el histórico al nuevo vocabulario del workflow
        DB::table('sales')->where('status', 'completada')->update(['status' => 'pagada']);
    }

    public function down(): void
    {
        DB::table('sales')->where('status', 'pagada')->whereNull('anulada_at')->update(['status' => 'completada']);

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'motivo_anulacion')) {
                $table->dropColumn('motivo_anulacion');
            }
            if (Schema::hasColumn('sales', 'anulada_por')) {
                $table->dropConstrainedForeignId('anulada_por');
            }
            if (Schema::hasColumn('sales', 'anulada_at')) {
                $table->dropColumn('anulada_at');
            }
            if (Schema::hasColumn('sales', 'appointment_id')) {
                $table->dropConstrainedForeignId('appointment_id');
            }
        });
    }
};
