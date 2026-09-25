<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FASE 4: Trazabilidad de lotes y caducidad + costo promedio móvil.
     * Los insumos se consumen con FIFO (First In, First Out) y el costo por
     * unidad se promedia móvilmente al comprar.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('items', 'costo_promedio')) {
            Schema::table('items', function (Blueprint $table) {
                $table->decimal('costo_promedio', 15, 6)->default(0)->after('precio_usd');
            });
        }

        if (!Schema::hasTable('lotes')) {
            Schema::create('lotes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_id')->constrained('items');
                $table->string('lote', 100);
                $table->date('fecha_entrada');
                $table->date('fecha_vencimiento')->nullable();
                $table->decimal('cantidad_entrada', 12, 4);
                $table->decimal('cantidad_disponible', 12, 4);
                $table->decimal('costo_unitario', 15, 6);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('costo_promedio');
        });
    }
};