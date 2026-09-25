<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FASE 5 AVANZADA: Nómina avanzada
     * - Rangos de comisión escalonados por esquema
     * - Amortización de adelantos (cuotas)
     * - Configuración de portal colaborador
     */
    public function up(): void
    {
        // 1. Rangos de comisión escalonados por esquema
        if (!Schema::hasTable('esquema_rangos')) {
            Schema::create('esquema_rangos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('esquema_comision_id')->constrained('esquemas_comision')->cascadeOnDelete();
                $table->decimal('desde', 15, 2)->default(0);
                $table->decimal('hasta', 15, 2)->nullable(); // null = sin límite superior
                $table->decimal('porcentaje', 5, 2); // porcentaje de comisión en este rango
                $table->integer('orden')->default(0); // para ordenar rangos
                $table->timestamps();
            });
        }

        // 2. Amortización de adelantos (cuotas)
        if (!Schema::hasTable('adelanto_cuotas')) {
            Schema::create('adelanto_cuotas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('adelanto_id')->constrained('advances')->cascadeOnDelete();
                $table->integer('numero_cuota');
                $table->date('fecha_vencimiento');
                $table->decimal('monto', 15, 2);
                $table->enum('estado', ['pendiente', 'pagada', 'parcial'])->default('pendiente');
                $table->decimal('monto_pagado', 15, 2)->default(0);
                $table->date('fecha_pago')->nullable();
                $table->timestamps();
            });
        }

        // 3. Configuración portal colaborador (parámetros globales)
        if (!Schema::hasTable('portal_colaborador_config')) {
            Schema::create('portal_colaborador_config', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 100)->unique();
                $table->text('valor')->nullable();
                $table->string('descripcion', 255)->nullable();
                $table->timestamps();
            });

            // Configuración por defecto
            DB::table('portal_colaborador_config')->insert([
                ['clave' => 'portal_habilitado', 'valor' => 'true', 'descripcion' => 'Habilitar portal del colaborador'],
                ['clave' => 'dias_historial', 'valor' => '90', 'descripcion' => 'Días de historial de ventas/comisiones visibles'],
                ['clave' => 'mostrar_metas', 'valor' => 'true', 'descripcion' => 'Mostrar progreso de metas de venta'],
                ['clave' => 'notificar_novedades', 'valor' => 'true', 'descripcion' => 'Enviar notificaciones por email/sms'],
            ]);
        }

        // 4. Tokens de acceso al portal colaborador
        if (!Schema::hasTable('portal_colaborador_tokens')) {
            Schema::create('portal_colaborador_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
                $table->string('token', 64)->unique();
                $table->timestamp('expira_en');
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esquema_rangos');
        Schema::dropIfExists('adelanto_cuotas');
        Schema::dropIfExists('portal_colaborador_config');
        Schema::dropIfExists('portal_colaborador_tokens');
    }
};