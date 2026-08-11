<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_por_pagar_id')->constrained('cuentas_por_pagar')->onDelete('cascade');
            
            $table->date('fecha');
            $table->decimal('monto', 15, 2);
            $table->string('forma_pago'); 
            
            
            $table->unsignedBigInteger('origen_pago_id')->nullable(); 
            
            $table->foreignId('usuario_id')->constrained('users'); // Quién registró el pago
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos_proveedor');
    }
};
