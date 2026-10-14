<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 - CLIENTES COMO CENTRO DE CUENTAS POR COBRAR (AR, octubre 2026).
 *
 * Eleva la tabla clients de directorio plano a perfil comercial con
 * inteligencia financiera:
 *  - customer_group_id: segmento (VIP/Regular/Mayorista) con descuento en POS.
 *  - credit_limit: límite de crédito (NULL = sin límite definido). El POS
 *    bloquea la facturación a crédito (CxC) al excederlo.
 *  - credit_days: días de crédito para calcular vencimiento de facturas
 *    emitidas; con facturas vencidas también se bloquea el crédito.
 *  - birthday / notes: fidelización y notas comerciales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('customer_group_id')
                ->nullable()
                ->constrained('customer_groups')
                ->nullOnDelete();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->unsignedInteger('credit_days')->default(0);
            $table->date('birthday')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['customer_group_id']);
            $table->dropColumn(['customer_group_id', 'credit_limit', 'credit_days', 'birthday', 'notes']);
        });
    }
};
