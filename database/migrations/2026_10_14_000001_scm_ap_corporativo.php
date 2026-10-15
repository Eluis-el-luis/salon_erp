<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CADENA DE SUMINISTRO Y CUENTAS POR PAGAR CORPORATIVAS (octubre 2026):
 *  - providers: datos fiscales y condiciones de crédito (RUC, días de crédito, activo).
 *  - purchase_invoices + purchase_invoice_lines: factura de proveedor con
 *    desglose de impuestos y gastos de envío (landed cost capitalizado al costo).
 *  - stock_ledger_entries: KÁRDEX INMUTABLE con saldo corrido valorado a costo
 *    promedio móvil. El inventario no se edita: todo cambio es un registro
 *    de entrada (compra/ajuste) o salida (venta/merma) con su valor.
 *  - cuentas_por_pagar: enlace a la factura de compra, número de documento
 *    del proveedor y estado 'anulada' (reversión sin borrado físico, §5.3).
 *  - lotes: enlace opcional a la línea de compra que los originó (auditoría
 *    de consumo por lote de una compra concreta).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. PROVEEDORES: condiciones comerciales
        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'tax_id')) {
                $table->string('tax_id', 30)->nullable()->after('name');
            }
            if (! Schema::hasColumn('providers', 'credit_days')) {
                $table->unsignedInteger('credit_days')->default(30)->after('notes');
            }
            if (! Schema::hasColumn('providers', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('credit_days');
            }
        });

        // 2. FACTURAS DE COMPRA (cabecera).
        // cuenta_por_pagar_id queda sin FK a propósito (relación cíclica con
        // cuentas_por_pagar.purchase_invoice_id); la referencia enlazada vive
        // del lado de la CxP, igual que origen_pago_id en pagos_proveedor.
        if (! Schema::hasTable('purchase_invoices')) {
            Schema::create('purchase_invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
                $table->unsignedBigInteger('cuenta_por_pagar_id')->nullable()->index();
                $table->string('numero_documento', 60)->nullable();
                $table->date('fecha_factura');
                $table->enum('condicion_pago', ['credito', 'contado'])->default('credito');
                $table->string('metodo_pago_contado', 20)->nullable();
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('descuento', 15, 2)->default(0);
                $table->decimal('gastos_envio', 15, 2)->default(0);
                $table->decimal('impuestos', 15, 2)->default(0);
                $table->decimal('total', 15, 2)->default(0);
                $table->enum('estado', ['registrada', 'anulada'])->default('registrada');
                $table->unsignedBigInteger('asiento_contable_id')->nullable();
                $table->text('notas')->nullable();
                $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['provider_id', 'fecha_factura']);
            });
        }

        // 3. LÍNEAS DE FACTURA DE COMPRA (detalle con impuesto y costo landed)
        if (! Schema::hasTable('purchase_invoice_lines')) {
            Schema::create('purchase_invoice_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
                $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
                $table->string('descripcion', 255);
                $table->decimal('cantidad', 12, 4);
                $table->decimal('costo_unitario', 15, 6);
                $table->decimal('costo_unitario_final', 15, 6);
                $table->decimal('impuesto_porcentaje', 5, 2)->default(0);
                $table->decimal('impuesto_monto', 15, 2)->default(0);
                $table->decimal('valor_neto', 15, 2)->default(0);
                $table->decimal('total_linea', 15, 2)->default(0);
                $table->string('lote', 100)->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->timestamps();
            });
        }

        // 4. KÁRDEX INMUTABLE (stock ledger). Valoración: para fraccionables la
        // medida primaria es el volumen (ml/oz); para el resto, la unidad.
        if (! Schema::hasTable('stock_ledger_entries')) {
            Schema::create('stock_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
                $table->dateTime('fecha');
                $table->enum('movimiento', ['entrada', 'salida', 'ajuste']);
                $table->string('origen', 40);
                $table->string('referencia_tipo', 40)->nullable();
                $table->unsignedBigInteger('referencia_id')->nullable();
                $table->decimal('cantidad', 15, 4);
                $table->decimal('volumen', 15, 4)->nullable();
                $table->decimal('costo_unitario', 15, 6)->default(0);
                $table->decimal('valor', 15, 2)->default(0);
                $table->decimal('saldo_cantidad', 15, 4)->default(0);
                $table->decimal('saldo_volumen', 15, 4)->nullable();
                $table->decimal('saldo_valor', 15, 2)->default(0);
                $table->decimal('costo_promedio', 15, 6)->default(0);
                $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('notas', 255)->nullable();
                $table->timestamps();

                $table->index(['item_id', 'fecha']);
                $table->index(['item_id', 'id']);
                $table->index(['referencia_tipo', 'referencia_id']);
            });
        }

        // 5. CUENTAS POR PAGAR: enlace a la factura de compra, documento del proveedor
        Schema::table('cuentas_por_pagar', function (Blueprint $table) {
            if (! Schema::hasColumn('cuentas_por_pagar', 'purchase_invoice_id')) {
                $table->foreignId('purchase_invoice_id')->nullable()->after('gasto_id')->constrained('purchase_invoices')->nullOnDelete();
            }
            if (! Schema::hasColumn('cuentas_por_pagar', 'numero_documento')) {
                $table->string('numero_documento', 60)->nullable()->after('provider_id');
            }
        });

        // Estado 'anulada' para reversiones sin borrado físico (§5.3)
        DB::statement("ALTER TABLE cuentas_por_pagar MODIFY estado ENUM('pendiente','parcial','pagada','anulada') NOT NULL DEFAULT 'pendiente'");

        // 6. LOTES: origen (línea de compra) para auditar consumo por compra
        Schema::table('lotes', function (Blueprint $table) {
            if (! Schema::hasColumn('lotes', 'purchase_invoice_line_id')) {
                $table->foreignId('purchase_invoice_line_id')->nullable()->after('item_id')->constrained('purchase_invoice_lines')->nullOnDelete();
            }
        });

        // 7. CATÁLOGO CONTABLE (idempotente; delegado al seeder compartido con
        //    los tests, porque el catálogo base lo llena ContabilidadBaseSeeder
        //    DESPUÉS de migrar en instalaciones nuevas). Garantiza las cuentas
        //    1.1.7 (IVA Crédito Fiscal) y 6.8 (Mermas) en bases existentes.
        (new \Database\Seeders\CuentasComplementariasSeeder())->run();
    }

    public function down(): void
    {
        // Revertir el enum sin dejar estados inválidos
        DB::table('cuentas_por_pagar')->where('estado', 'anulada')->update(['estado' => 'pendiente']);
        DB::statement("ALTER TABLE cuentas_por_pagar MODIFY estado ENUM('pendiente','parcial','pagada') NOT NULL DEFAULT 'pendiente'");

        Schema::table('cuentas_por_pagar', function (Blueprint $table) {
            if (Schema::hasColumn('cuentas_por_pagar', 'purchase_invoice_id')) {
                $table->dropForeign(['purchase_invoice_id']);
                $table->dropColumn('purchase_invoice_id');
            }
            if (Schema::hasColumn('cuentas_por_pagar', 'numero_documento')) {
                $table->dropColumn('numero_documento');
            }
        });

        Schema::table('lotes', function (Blueprint $table) {
            if (Schema::hasColumn('lotes', 'purchase_invoice_line_id')) {
                $table->dropForeign(['purchase_invoice_line_id']);
                $table->dropColumn('purchase_invoice_line_id');
            }
        });

        Schema::dropIfExists('stock_ledger_entries');
        Schema::dropIfExists('purchase_invoice_lines');
        Schema::dropIfExists('purchase_invoices');

        Schema::table('providers', function (Blueprint $table) {
            foreach (['tax_id', 'credit_days', 'is_active'] as $columna) {
                if (Schema::hasColumn('providers', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
