<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_por_pagar', function (Blueprint $table) {
            $table->id();
            // Asumiendo que tu tabla de proveedores se llama 'providers' (por tu ProviderController)
            $table->foreignId('provider_id')->constrained('providers')->onDelete('cascade');
            
            // Lo dejamos nullable por ahora, ya que la tabla GASTOS la haremos en la Fase 2
            $table->unsignedBigInteger('gasto_id')->nullable(); 
            
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento');
            
            $table->decimal('monto_original', 15, 2);
            $table->decimal('saldo_pendiente', 15, 2);
            
            $table->enum('estado', ['pendiente', 'parcial', 'pagada'])->default('pendiente');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuentas_por_pagar');
    }
};
