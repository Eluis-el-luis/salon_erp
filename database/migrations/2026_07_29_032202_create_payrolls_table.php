<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users');
        $table->date('start_date');
        $table->date('end_date');
        
        // Columnas exactas de su Excel:
        $table->decimal('active_salary', 10, 2)->default(0); // Salario activo
        $table->decimal('services_commission', 10, 2)->default(0); // Servicios realizados
        $table->decimal('products_commission', 10, 2)->default(0); // Ventas de productos
        $table->decimal('extra_bonus', 10, 2)->default(0); // Algún bono extra
        $table->decimal('sunday_bonus', 10, 2)->default(0); // Día de domingo extra
        
        // Deducciones:
        $table->decimal('salary_advances', 10, 2)->default(0); // Adelantos de salario
        $table->decimal('loan_payments', 10, 2)->default(0); // Crédito y dan abono
        
        $table->decimal('total_to_pay', 10, 2); // Total a pagar
        $table->enum('status', ['borrador', 'pagado'])->default('borrador');
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
