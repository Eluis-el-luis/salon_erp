<?php

namespace Database\Seeders;

use App\Models\EstructuraSalarial;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DATOS BASE DEL MÓDULO HRMS (Recursos Humanos corporativo, octubre 2026).
 *
 * Inserta, de forma IDEMPOTENTE:
 *  - Tipos de ausencia (leave types) de la realidad nicaragüense.
 *  - Turnos operativos por defecto del salón.
 *  - La estructura salarial estándar (Salary Structure) con calculadoras dinámicas.
 *
 * Es invocado por la migración payroll_runs_colillas_corporativo, por db:seed
 * y por los tests, porque las tablas HR se crean en las migraciones.
 */
class RrhhBaseSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------------
        // 1. Tipos de ausencia
        // ------------------------------------------------------------------
        $tipos = [
            ['codigo' => 'VAC', 'nombre' => 'Vacaciones', 'pagado' => true, 'requiere_documento' => false],
            ['codigo' => 'MED', 'nombre' => 'Subsidio Médico (INSS)', 'pagado' => false, 'requiere_documento' => true],
            ['codigo' => 'PSS', 'nombre' => 'Permiso sin Goce de Sueldo', 'pagado' => false, 'requiere_documento' => false],
            ['codigo' => 'PCG', 'nombre' => 'Permiso con Goce de Sueldo', 'pagado' => true, 'requiere_documento' => false],
        ];

        foreach ($tipos as $tipo) {
            DB::table('leave_types')->updateOrInsert(
                ['codigo' => $tipo['codigo']],
                $tipo + ['activo' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // ------------------------------------------------------------------
        // 2. Turnos por defecto
        // ------------------------------------------------------------------
        $turnos = [
            ['nombre' => 'Turno Completo', 'hora_entrada' => '09:00:00', 'hora_salida' => '18:00:00', 'tolerancia_minutos' => 15],
            ['nombre' => 'Turno Mañana', 'hora_entrada' => '08:00:00', 'hora_salida' => '13:00:00', 'tolerancia_minutos' => 10],
            ['nombre' => 'Turno Tarde', 'hora_entrada' => '13:00:00', 'hora_salida' => '19:00:00', 'tolerancia_minutos' => 10],
        ];

        foreach ($turnos as $turno) {
            DB::table('shifts')->updateOrInsert(
                ['nombre' => $turno['nombre']],
                $turno + ['activo' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // ------------------------------------------------------------------
        // 3. Estructura salarial estándar del salón
        // ------------------------------------------------------------------
        $estructura = EstructuraSalarial::firstOrCreate(
            ['nombre' => 'Planilla Estándar del Salón'],
            [
                'descripcion' => 'Estructura corporativa: salario base + comisiones congeladas + propinas + horas extra; deducciones de ley (INSS/IR), ausencias sin goce, tardanzas y amortización de adelantos (CxC).',
                'activa' => true,
            ],
        );

        $detalles = [
            // INGRESOS
            ['codigo' => 'SAL_BASE', 'nombre' => 'Salario Base del Período', 'tipo' => 'ingreso', 'calculadora' => 'salario_base', 'valor' => 0, 'orden' => 10],
            ['codigo' => 'COM_VENT', 'nombre' => 'Comisiones de Ventas (congeladas)', 'tipo' => 'ingreso', 'calculadora' => 'comisiones', 'valor' => 0, 'orden' => 20],
            ['codigo' => 'PROPINAS', 'nombre' => 'Propinas Acumuladas en Custodia', 'tipo' => 'ingreso', 'calculadora' => 'propinas', 'valor' => 0, 'orden' => 30],
            ['codigo' => 'HORAS_EXT', 'nombre' => 'Horas Extras', 'tipo' => 'ingreso', 'calculadora' => 'horas_extras', 'valor' => 2.0, 'orden' => 40],
            // DEDUCCIONES
            ['codigo' => 'INSS', 'nombre' => 'INSS Laboral', 'tipo' => 'deduccion', 'calculadora' => 'inss', 'valor' => 0, 'orden' => 100],
            ['codigo' => 'IR', 'nombre' => 'Impuesto sobre la Renta', 'tipo' => 'deduccion', 'calculadora' => 'ir', 'valor' => 0, 'orden' => 110],
            ['codigo' => 'SIN_GOCE', 'nombre' => 'Ausencias sin Goce de Sueldo', 'tipo' => 'deduccion', 'calculadora' => 'sin_goce', 'valor' => 0, 'orden' => 120],
            ['codigo' => 'TARDANZAS', 'nombre' => 'Deducción por Llegadas Tardías', 'tipo' => 'deduccion', 'calculadora' => 'tardanzas', 'valor' => 50, 'orden' => 130],
            ['codigo' => 'ADELANTOS', 'nombre' => 'Amortización de Adelantos (CxC)', 'tipo' => 'deduccion', 'calculadora' => 'adelantos', 'valor' => 0, 'orden' => 140],
        ];

        foreach ($detalles as $detalle) {
            DB::table('salary_structure_details')->updateOrInsert(
                ['salary_structure_id' => $estructura->id, 'codigo' => $detalle['codigo']],
                $detalle + ['cuenta_contable_id' => null, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
