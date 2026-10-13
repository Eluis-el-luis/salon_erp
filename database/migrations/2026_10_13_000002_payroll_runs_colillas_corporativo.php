<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Database\Seeders\RrhhBaseSeeder;

/**
 * HRMS - Motor de Nómina (octubre 2026).
 *
 * - Corridas de nómina (Payroll Runs) y colillas (Salary Slips) con detalle auditable.
 * - Asistencia ampliada: hora de salida, turno, tardanzas y horas extra.
 * - Cuentas complementarias: 2.1.6 Propinas por Pagar y 1.1.1.3 Propinas en Custodia.
 * - Trazabilidad de comisiones hacia su colilla.
 * - Backfill: un registro employee por cada usuario existente.
 * - Datos base RRHH (tipos de ausencia, turnos y estructura salarial estándar).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 1. CORRIDAS Y COLILLAS
        // ------------------------------------------------------------------
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('periodo_inicio');
            $table->date('periodo_fin');
            $table->enum('metodo_pago', ['efectivo', 'banco'])->default('banco');
            $table->enum('estado', ['borrador', 'contabilizada', 'anulada'])->default('borrador');
            $table->decimal('total_ingresos', 12, 2)->default(0);
            $table->decimal('total_deducciones', 12, 2)->default(0);
            $table->decimal('total_neto', 12, 2)->default(0);
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['estado', 'periodo_inicio']);
        });

        Schema::create('salary_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('periodo_inicio');
            $table->date('periodo_fin');
            $table->decimal('salario_base', 10, 2)->default(0);
            $table->decimal('total_ingresos', 12, 2)->default(0);
            $table->decimal('total_deducciones', 12, 2)->default(0);
            $table->decimal('neto_pagar', 12, 2)->default(0);
            $table->enum('estado', ['borrador', 'pagada', 'anulada'])->default('borrador');
            $table->date('fecha_pago')->nullable();
            $table->unsignedBigInteger('asiento_contable_id')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id']);
        });

        Schema::create('salary_slip_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_slip_id')->constrained('salary_slips')->cascadeOnDelete();
            $table->enum('tipo', ['ingreso', 'deduccion']);
            $table->string('codigo', 50);
            $table->string('concepto', 200);
            $table->string('fuente', 50)->default('estructura'); // estructura, comisiones, propinas, asistencia, ausencias, ley, adelantos, manual
            $table->decimal('monto', 10, 2)->default(0);
            $table->integer('orden')->default(0);
            $table->unsignedBigInteger('referencia_id')->nullable(); // ej. cuota de adelanto amortizada
            $table->timestamps();

            $table->index(['salary_slip_id', 'tipo']);
        });

        // ------------------------------------------------------------------
        // 2. ASISTENCIA AMPLIADA (turno, salida, tardanzas, horas extra)
        // ------------------------------------------------------------------
        if (Schema::hasTable('attendances') && !Schema::hasColumn('attendances', 'estado')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->foreignId('shift_id')->nullable()->after('user_id')->constrained('shifts')->nullOnDelete();
                $table->time('time_out')->nullable()->after('time_in');
                $table->integer('minutos_tardanza')->default(0)->after('time_out');
                $table->decimal('horas_trabajadas', 5, 2)->nullable()->after('minutos_tardanza');
                $table->decimal('horas_extras', 5, 2)->default(0)->after('horas_trabajadas');
                $table->enum('estado', ['asistio', 'tardanza', 'ausente', 'justificado'])->default('asistio')->after('horas_extras');
            });
            // Historial existente: registro con entrada se interpreta como asistencia puntual
            DB::table('attendances')->where('estado', '')->update(['estado' => 'asistio']);
        }

        // ------------------------------------------------------------------
        // 3. CUENTAS COMPLEMENTARIAS (idempotente, patrón payroll_deductions)
        // ------------------------------------------------------------------
        $pasivoCorriente = DB::table('cuentas_contables')->where('codigo', '2.1')->first();
        if ($pasivoCorriente && !DB::table('cuentas_contables')->where('codigo', '2.1.6')->exists()) {
            DB::table('cuentas_contables')->insert([
                'codigo' => '2.1.6',
                'nombre' => 'Propinas por Pagar',
                'tipo' => 'pasivo',
                'naturaleza' => 'acreedora',
                'cuenta_padre_id' => $pasivoCorriente->id,
                'nivel' => 3,
                'permite_movimiento' => true,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $cuentaCaja = DB::table('cuentas_contables')->where('codigo', '1.1.1')->first();
        if ($cuentaCaja && !DB::table('cuentas_contables')->where('codigo', '1.1.1.3')->exists()) {
            DB::table('cuentas_contables')->insert([
                'codigo' => '1.1.1.3',
                'nombre' => 'Propinas en Custodia',
                'tipo' => 'activo',
                'naturaleza' => 'deudora',
                'cuenta_padre_id' => $cuentaCaja->id,
                'nivel' => 4,
                'permite_movimiento' => true,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ------------------------------------------------------------------
        // 4. TRAZABILIDAD: comisiones vinculadas a su colilla
        // ------------------------------------------------------------------
        if (Schema::hasTable('comisiones_generadas') && !Schema::hasColumn('comisiones_generadas', 'salary_slip_id')) {
            Schema::table('comisiones_generadas', function (Blueprint $table) {
                $table->unsignedBigInteger('salary_slip_id')->nullable()->after('periodo_nomina_id');
            });
        }

        // ------------------------------------------------------------------
        // 5. BACKFILL: un Employee por cada usuario existente
        // ------------------------------------------------------------------
        if (Schema::hasTable('users')) {
            $usuarios = DB::table('users')->orderBy('id')->get();
            foreach ($usuarios as $usuario) {
                $existe = DB::table('employees')->where('user_id', $usuario->id)->exists();
                if ($existe) {
                    continue;
                }
                DB::table('employees')->insert([
                    'user_id' => $usuario->id,
                    'codigo_empleado' => 'EMP-' . str_pad($usuario->id, 4, '0', STR_PAD_LEFT),
                    'tipo_contrato' => 'indefinido',
                    'fecha_ingreso' => $usuario->created_at ? date('Y-m-d', strtotime($usuario->created_at)) : now()->toDateString(),
                    'estado_laboral' => ($usuario->is_active ?? true) ? 'activo' : 'retirado',
                    'salario_base' => $usuario->salario_fijo ?? 0,
                    'periodicidad_pago' => 'quincenal',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ------------------------------------------------------------------
        // 6. DATOS BASE RRHH (tipos de ausencia, turnos, estructura estándar)
        // ------------------------------------------------------------------
        (new RrhhBaseSeeder())->run();
    }

    public function down(): void
    {
        if (Schema::hasTable('comisiones_generadas') && Schema::hasColumn('comisiones_generadas', 'salary_slip_id')) {
            Schema::table('comisiones_generadas', function (Blueprint $table) {
                $table->dropColumn('salary_slip_id');
            });
        }

        DB::table('cuentas_contables')->whereIn('codigo', ['2.1.6', '1.1.1.3'])->delete();

        if (Schema::hasTable('attendances') && Schema::hasColumn('attendances', 'estado')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropConstrainedForeignId('shift_id');
                $table->dropColumn(['time_out', 'minutos_tardanza', 'horas_trabajadas', 'horas_extras', 'estado']);
            });
        }

        Schema::dropIfExists('salary_slip_details');
        Schema::dropIfExists('salary_slips');
        Schema::dropIfExists('payroll_runs');
    }
};
