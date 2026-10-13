<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS - Núcleo de Recursos Humanos (octubre 2026).
 *
 * Separa el concepto de User (acceso al sistema) del concepto de Employee
 * (datos de RRHH), y agrega gestión de turnos, permisos (leave management),
 * estructuras salariales (Salary Structures) y propinas acumuladas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 1. ESTRUCTURAS SALARIALES (Salary Structures)
        // ------------------------------------------------------------------
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120)->unique();
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('salary_structure_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained('salary_structures')->cascadeOnDelete();
            $table->string('codigo', 50);              // SAL_BASE, COM_VENT, INSS...
            $table->string('nombre', 150);             // Etiqueta legible
            $table->enum('tipo', ['ingreso', 'deduccion']);
            // Calculadora dinámica: qué motor calcula el monto de esta línea
            $table->string('calculadora', 40);         // salario_base, comisiones, propinas, horas_extras, inss, ir, sin_goce, tardanzas, adelantos, fijo
            $table->decimal('valor', 10, 4)->default(0); // Parámetro (multiplicador, monto fijo, etc.)
            $table->integer('orden')->default(0);
            $table->unsignedBigInteger('cuenta_contable_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['salary_structure_id', 'codigo'], 'uq_estructura_codigo');
        });

        // ------------------------------------------------------------------
        // 2. EMPLEADOS (ciclo de vida separado de users)
        // ------------------------------------------------------------------
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('codigo_empleado', 20)->unique();
            $table->foreignId('salary_structure_id')->nullable()->constrained('salary_structures')->nullOnDelete();
            $table->enum('tipo_contrato', ['indefinido', 'plazo_fijo', 'obra_labor', 'servicios_profesionales'])->default('indefinido');
            $table->date('fecha_ingreso')->nullable();
            $table->date('fecha_salida')->nullable();
            $table->enum('estado_laboral', ['activo', 'vacaciones', 'licencia', 'suspendido', 'retirado'])->default('activo');
            $table->string('cargo', 120)->nullable();
            $table->decimal('salario_base', 10, 2)->default(0);
            $table->enum('periodicidad_pago', ['semanal', 'quincenal', 'mensual'])->default('quincenal');
            $table->string('documento_identidad', 50)->nullable();
            $table->string('nss', 50)->nullable();             // Número INSS
            $table->string('banco_deposito', 120)->nullable();
            $table->string('cuenta_bancaria_deposito', 60)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // ------------------------------------------------------------------
        // 3. TURNOS (Shift Types + Shift Assignments)
        // ------------------------------------------------------------------
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120)->unique();
            $table->time('hora_entrada');
            $table->time('hora_salida');
            $table->integer('tolerancia_minutos')->default(10);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'vigente_desde']);
        });

        // ------------------------------------------------------------------
        // 4. PERMISOS Y AUSENCIAS (Leave Management)
        // ------------------------------------------------------------------
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 120)->unique();
            $table->boolean('pagado')->default(true);          // false => deducción proporcional de salario base
            $table->boolean('requiere_documento')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('dias', 5, 2)->default(1);
            $table->text('motivo')->nullable();
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada', 'cancelada'])->default('pendiente');
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_revision')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'estado']);
            $table->index(['fecha_inicio', 'fecha_fin']);
        });

        // ------------------------------------------------------------------
        // 5. PROPINAS ACUMULADAS EN CUSTODIA (Tips)
        // ------------------------------------------------------------------
        Schema::create('tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('monto', 10, 2);
            $table->unsignedBigInteger('venta_id')->nullable();
            $table->enum('estado', ['pendiente', 'pagada'])->default('pendiente');
            $table->unsignedBigInteger('salary_slip_id')->nullable();
            $table->unsignedBigInteger('asiento_contable_id')->nullable();
            $table->string('observacion', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tips');
        Schema::dropIfExists('leave_applications');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('salary_structure_details');
        Schema::dropIfExists('salary_structures');
    }
};
