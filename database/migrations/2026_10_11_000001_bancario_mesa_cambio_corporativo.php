<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase corporativa de Bancos y Mesa de Cambio:
 *
 * BANCOS
 *  - cuentas_bancarias.cuenta_contable_id: cada cuenta bancaria apunta a SU
 *    subcuenta del mayor (estilo ERPNext) para soportar BAC/LAFISE en NIO y USD.
 *  - movimientos_bancarios: moneda del movimiento, referencia del banco,
 *    asiento contable vinculado y estado de conciliación (clearing).
 *  - conciliaciones_bancarias: historial de estados de cuenta conciliados.
 *  - transferencias: moneda, concepto, referencia y comisión bancaria.
 *
 * MESA DE CAMBIO
 *  - operaciones_cambio: separa origen de fondos (moneda que sale) del destino
 *    (moneda que entra), registra la tasa oficial del día y el diferencial
 *    contable contra el costo promedio ponderado, y vincula el asiento.
 *
 * CATÁLOGO CONTABLE (idempotente, siguiendo el patrón add_cuentas_faltantes_fase1)
 *  - 4.6  Ganancia por Diferencial de Cambio (ingreso, acreedora)
 *  - 4.7  Ingresos por Intereses y Rendimientos (ingreso, acreedora)
 *  - 6.9  Comisiones y Cargos Bancarios (gasto, deudora)
 *  - 7.5  Pérdida por Diferencial de Cambio (gasto, deudora)
 *  - 1.1.2.3 Banco BAC Dólares (USD)
 *  - 1.1.2.4 Banco Lafise Dólares (USD)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 1. CUENTAS BANCARIAS: vínculo con el mayor y alias legible
        // ------------------------------------------------------------------
        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            if (!Schema::hasColumn('cuentas_bancarias', 'alias')) {
                $table->string('alias')->nullable()->after('numero_cuenta');
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'cuenta_contable_id')) {
                $table->unsignedBigInteger('cuenta_contable_id')->nullable()->after('moneda_id');
                $table->index('cuenta_contable_id');
            }
        });

        // ------------------------------------------------------------------
        // 2. MOVIMIENTOS BANCARIOS: conciliación y trazabilidad
        // ------------------------------------------------------------------
        Schema::table('movimientos_bancarios', function (Blueprint $table) {
            if (!Schema::hasColumn('movimientos_bancarios', 'moneda_id')) {
                $table->unsignedBigInteger('moneda_id')->nullable()->after('cuenta_bancaria_id');
            }
            if (!Schema::hasColumn('movimientos_bancarios', 'estado_conciliacion')) {
                $table->enum('estado_conciliacion', ['pendiente', 'en_transito', 'conciliado'])
                    ->default('pendiente')->after('concepto');
            }
            if (!Schema::hasColumn('movimientos_bancarios', 'fecha_conciliacion')) {
                $table->date('fecha_conciliacion')->nullable()->after('estado_conciliacion');
            }
            if (!Schema::hasColumn('movimientos_bancarios', 'referencia_banco')) {
                $table->string('referencia_banco')->nullable()->after('fecha_conciliacion');
            }
            if (!Schema::hasColumn('movimientos_bancarios', 'asiento_contable_id')) {
                $table->unsignedBigInteger('asiento_contable_id')->nullable()->after('referencia_banco');
            }
            if (!Schema::hasColumn('movimientos_bancarios', 'conciliacion_id')) {
                $table->unsignedBigInteger('conciliacion_id')->nullable()->after('asiento_contable_id');
            }
        });

        // Compatibilidad: los movimientos marcados con el booleano legado
        // heredan su estado en el nuevo campo.
        DB::table('movimientos_bancarios')
            ->where('conciliado', true)
            ->whereIn('estado_conciliacion', ['pendiente', 'en_transito'])
            ->update(['estado_conciliacion' => 'conciliado']);

        // ------------------------------------------------------------------
        // 3. CONCILIACIONES BANCARIAS (historial de arqueos vs. extracto)
        // ------------------------------------------------------------------
        if (!Schema::hasTable('conciliaciones_bancarias')) {
            Schema::create('conciliaciones_bancarias', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias')->onDelete('cascade');
                $table->date('fecha');                       // fecha del extracto bancario
                $table->decimal('saldo_inicial_conciliado', 15, 2)->default(0);
                $table->decimal('saldo_extracto', 15, 2);    // saldo según el banco
                $table->decimal('saldo_libros', 15, 2);      // saldo según el sistema
                $table->decimal('diferencia', 15, 2)->default(0);
                $table->enum('estado', ['borrador', 'cerrada'])->default('borrador');
                $table->text('observaciones')->nullable();
                $table->foreignId('usuario_id')->constrained('users');
                $table->timestamps();
            });
        }

        // ------------------------------------------------------------------
        // 4. TRANSFERENCIAS: moneda, comisión bancaria y referencia
        // ------------------------------------------------------------------
        Schema::table('transferencias', function (Blueprint $table) {
            if (!Schema::hasColumn('transferencias', 'moneda_id')) {
                $table->unsignedBigInteger('moneda_id')->nullable()->after('monto');
            }
            if (!Schema::hasColumn('transferencias', 'concepto')) {
                $table->string('concepto')->nullable()->after('moneda_id');
            }
            if (!Schema::hasColumn('transferencias', 'referencia_bancaria')) {
                $table->string('referencia_bancaria')->nullable()->after('concepto');
            }
            if (!Schema::hasColumn('transferencias', 'comision')) {
                $table->decimal('comision', 15, 2)->default(0)->after('referencia_bancaria');
            }
        });

        // ------------------------------------------------------------------
        // 5. OPERACIONES DE CAMBIO: bóvedas de origen/destino, tasa oficial
        //    y diferencial contable real (vs. costo promedio)
        // ------------------------------------------------------------------
        Schema::table('operaciones_cambio', function (Blueprint $table) {
            if (!Schema::hasColumn('operaciones_cambio', 'destino_pago_tipo')) {
                $table->string('destino_pago_tipo')->nullable()->after('origen_pago_id');
            }
            if (!Schema::hasColumn('operaciones_cambio', 'destino_pago_id')) {
                $table->unsignedBigInteger('destino_pago_id')->nullable()->after('destino_pago_tipo');
            }
            if (!Schema::hasColumn('operaciones_cambio', 'tasa_oficial')) {
                $table->decimal('tasa_oficial', 15, 6)->nullable()->after('tasa_aplicada');
            }
            if (!Schema::hasColumn('operaciones_cambio', 'tasa_costo_usd')) {
                $table->decimal('tasa_costo_usd', 15, 6)->nullable()->after('tasa_oficial');
            }
            if (!Schema::hasColumn('operaciones_cambio', 'diferencial_costo')) {
                $table->decimal('diferencial_costo', 15, 2)->nullable()->after('tasa_costo_usd');
            }
            if (!Schema::hasColumn('operaciones_cambio', 'diferencial_oficial')) {
                $table->decimal('diferencial_oficial', 15, 2)->nullable()->after('diferencial_costo');
            }
            if (!Schema::hasColumn('operaciones_cambio', 'asiento_contable_id')) {
                $table->unsignedBigInteger('asiento_contable_id')->nullable()->after('usuario_id');
            }
            if (!Schema::hasColumn('operaciones_cambio', 'referencia')) {
                $table->string('referencia')->nullable()->after('asiento_contable_id');
            }
        });

        // Compatibilidad con datos existentes: en las operaciones legacy el
        // origen y el destino de fondos eran la misma ubicación.
        DB::table('operaciones_cambio')
            ->whereNull('destino_pago_tipo')
            ->update([
                'destino_pago_tipo' => DB::raw('origen_pago_tipo'),
                'destino_pago_id' => DB::raw('origen_pago_id'),
            ]);

        // ------------------------------------------------------------------
        // 6. CATÁLOGO CONTABLE (idempotente; delegado al seeder compartido
        //    con los tests, porque el catálogo base lo llena ContabilidadBaseSeeder
        //    DESPUÉS de las migraciones en instalaciones nuevas).
        // ------------------------------------------------------------------
        (new \Database\Seeders\CuentasComplementariasSeeder())->run();
    }

    public function down(): void
    {
        Schema::table('operaciones_cambio', function (Blueprint $table) {
            foreach (['referencia', 'asiento_contable_id', 'diferencial_oficial', 'diferencial_costo', 'tasa_costo_usd', 'tasa_oficial', 'destino_pago_id', 'destino_pago_tipo'] as $columna) {
                if (Schema::hasColumn('operaciones_cambio', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::table('transferencias', function (Blueprint $table) {
            foreach (['comision', 'referencia_bancaria', 'concepto', 'moneda_id'] as $columna) {
                if (Schema::hasColumn('transferencias', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::dropIfExists('conciliaciones_bancarias');

        Schema::table('movimientos_bancarios', function (Blueprint $table) {
            foreach (['conciliacion_id', 'asiento_contable_id', 'referencia_banco', 'fecha_conciliacion', 'estado_conciliacion', 'moneda_id'] as $columna) {
                if (Schema::hasColumn('movimientos_bancarios', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            foreach (['cuenta_contable_id', 'alias'] as $columna) {
                if (Schema::hasColumn('cuentas_bancarias', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        // Sólo se eliminan las cuentas nuevas si no tienen movimientos contables.
        foreach (['4.6', '4.7', '6.9', '7.5', '1.1.2.3', '1.1.2.4'] as $codigo) {
            $id = DB::table('cuentas_contables')->where('codigo', $codigo)->value('id');
            if ($id && !DB::table('detalle_asientos')->where('cuenta_id', $id)->exists()) {
                DB::table('cuentas_contables')->where('id', $id)->delete();
            }
        }
    }
};
