<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Rol del empleado en el sistema
            $table->enum('role', ['admin', 'estilista', 'recepcion'])->default('estilista');
            
            // Datos para la Nómina 
            $table->decimal('salario_fijo', 10, 2)->default(0); // Ej: 1850.00
            
            // Porcentajes de comisión (Se guardan como porcentaje, ej. 40.00 para 40%)
            $table->decimal('comision_servicio', 5, 2)->default(0); 
            $table->decimal('comision_producto', 5, 2)->default(0);
            
            // Control de acceso y contacto
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true); // Para suspender empleados sin borrar su historial
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'salario_fijo', 'comision_servicio', 'comision_producto', 'phone', 'is_active']);
        });
    }
};