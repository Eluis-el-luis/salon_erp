<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_bancarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias')->onDelete('cascade');
            
            $table->enum('tipo', ['deposito', 'retiro', 'cargo', 'abono']);
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->string('concepto');
            
            // Origen de la transacción (Ej: 'pagos_proveedor', 'transferencias')
            $table->string('modulo_origen');
            $table->unsignedBigInteger('referencia_id');
            
            $table->boolean('conciliado')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_bancarios');
    }
};
