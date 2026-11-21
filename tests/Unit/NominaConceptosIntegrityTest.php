<?php

namespace Tests\Unit;

use App\Exceptions\ContabilidadException;
use App\Models\ColillaNomina;
use App\Services\{AccountIntegrityService, ContabilidadService, NominaService};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\{DateFactory, Facades\Facade};
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Support\H07MemoryConnection;

/** Reutiliza el doble H07 sin PDO; las fórmulas y emisión son las del ERP. */
final class NominaConceptosIntegrityTest extends TestCase
{
    private H07MemoryConnection $db;
    private NominaService $service;
    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container; Container::setInstance($app);
        $app->instance('config', new Repository);
        $app->instance('date', new DateFactory);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = new H07MemoryConnection;
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['mysql' => $this->db]); $resolver->setDefaultConnection('mysql');
        Model::setConnectionResolver($resolver); $app->instance('db', $this->db);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class);
        $auth->shouldReceive('id')->andReturn(99); $app->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
        $log = Mockery::mock(); $log->shouldReceive('error'); $app->instance('log', $log);
        $integrity = Mockery::mock(AccountIntegrityService::class);
        $integrity->shouldReceive('posting'); $app->instance(AccountIntegrityService::class, $integrity);
        $this->service = new NominaService(new H08Accounting);
        foreach ([1, 2] as $id) {
            $this->db->rows['employees'][$id] = ['id' => $id, 'user_id' => 10 + $id, 'codigo_empleado' => 'H08-'.$id,
                'salary_structure_id' => $id, 'salario_base' => '1000.00'];
            $this->db->rows['users'][10 + $id] = ['id' => 10 + $id, 'name' => 'H08 sintético '.$id, 'salario_fijo' => 0];
            $this->db->rows['salary_structures'][$id] = ['id' => $id, 'activa' => true];
            $this->db->rows['salary_structure_details'][$id] = ['id' => $id, 'salary_structure_id' => $id,
                'codigo' => 'SAL_BASE', 'tipo' => 'ingreso', 'calculadora' => 'salario_base', 'activo' => true, 'orden' => 10];
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        parent::tearDown();
    }

    private function datos(array $extra = []): array
    {
        return array_replace(['periodo_inicio' => '2026-11-01', 'periodo_fin' => '2026-11-15',
            'metodo_pago' => 'banco', 'empleado_ids' => [1]], $extra);
    }

    private function fixed(string $code, string $type, string $amount, int $employee = 1): void
    {
        $this->db->rows['salary_structure_details'][10] = ['id' => 10, 'salary_structure_id' => $employee,
            'codigo' => $code, 'nombre' => 'Fijo sintético', 'tipo' => $type, 'calculadora' => 'fijo',
            'valor' => $amount, 'activo' => true, 'orden' => 20];
    }

    private function rejectWithoutEffects(array $datos): void
    {
        $before = $this->db->rows;
        try {
            $this->service->procesar($datos);
            $journal = $this->db->rows['detalle_asientos'];
            $fund = array_sum(array_column(array_filter($journal, fn ($r) => $r['cuenta_id'] === 1121), 'haber'));
            self::fail('Aceptó concepto sin tratamiento: '.json_encode(['neto' => $this->db->rows['salary_slips'][1]['neto_pagar'],
                'fondo' => $fund, 'debe' => array_sum(array_column($journal, 'debe')), 'haber' => array_sum(array_column($journal, 'haber'))]));
        }
        catch (ContabilidadException $e) { self::assertNotEmpty($e->getMessage()); }
        self::assertSame($before, $this->db->rows);
        self::assertSame(0, $this->db->transactionLevel());
        self::assertNotContains('insert:payroll_runs', $this->db->trace);
    }

    public function test_free_bonus_is_rejected_before_creating_effects(): void
    {
        $this->fixed('BONO', 'ingreso', '100.00');
        $this->rejectWithoutEffects($this->datos());
    }

    public function test_free_deduction_is_rejected_before_creating_effects(): void
    {
        $this->fixed('DESCUENTO_LIBRE', 'deduccion', '100.00');
        $this->rejectWithoutEffects($this->datos());
    }

    public function test_fixed_with_existing_code_and_account_cannot_grant_itself_a_policy(): void
    {
        $this->fixed('INSS', 'deduccion', '10.00');
        $this->db->rows['salary_structure_details'][10]['cuenta_contable_id'] = 215;
        $this->rejectWithoutEffects($this->datos());
    }

    public function test_invalid_second_employee_prevents_the_entire_run(): void
    {
        $this->fixed('BONO', 'ingreso', '100.00', 2);
        $this->rejectWithoutEffects($this->datos(['empleado_ids' => [1, 2]]));
    }

    public function test_negative_base_or_source_is_rejected_instead_of_disappearing_from_lines(): void
    {
        $this->db->rows['employees'][1]['salario_base'] = '-1.00';
        $this->rejectWithoutEffects($this->datos());
        $this->db->rows['employees'][1]['salario_base'] = '1000.00';
        $this->calculator(3, 'PROPINAS', 'ingreso', 'propinas'); $this->sources('-1.00');
        $this->rejectWithoutEffects($this->datos());
    }

    public function test_zero_calculated_base_is_representable_without_unknown_fixed_concepts(): void
    {
        $this->db->rows['employees'][1]['salario_base'] = '0.00';
        $run = $this->service->procesar($this->datos());
        self::assertSame('contabilizada', $run->estado);
        self::assertSame('0.00', $run->total_neto);
        self::assertSame([], $this->db->rows['salary_slip_details']);
    }

    public function test_direct_accounting_consumer_cannot_bypass_concept_validation(): void
    {
        $this->db->rows['salary_slips'][1] = ['id' => 1, 'total_ingresos' => '100', 'total_deducciones' => '0', 'neto_pagar' => '100'];
        $this->db->rows['salary_slip_details'][1] = ['id' => 1, 'salary_slip_id' => 1, 'codigo' => 'BONO',
            'tipo' => 'ingreso', 'fuente' => 'manual', 'monto' => '100.00', 'orden' => 1];
        $colilla = ColillaNomina::find(1);
        $before = $this->db->rows;
        try { (new H08Accounting)->contabilizarColillaNomina($colilla); self::fail('Consumidor directo eludió el contrato.'); }
        catch (ContabilidadException $e) { self::assertStringContainsString('tratamiento contable', $e->getMessage()); }
        self::assertSame($before, $this->db->rows); self::assertSame([], $this->db->trace);
    }

    public function test_balanced_but_incomplete_journal_is_rejected_by_real_emission(): void
    {
        $before = $this->db->rows; $observed = [];
        $this->db->afterInsert = function ($db, $table, $row) use (&$observed) {
            if ($table !== 'detalle_asientos' || $row['cuenta_id'] !== 1121) return;
            foreach ($db->rows['detalle_asientos'] as &$line) {
                if ($line['cuenta_id'] === 67) $line['debe'] = '900.00';
                if ($line['cuenta_id'] === 1121) $line['haber'] = '900.00';
            }
            unset($line);
            $observed = [array_sum(array_column($db->rows['detalle_asientos'], 'debe')),
                array_sum(array_column($db->rows['detalle_asientos'], 'haber'))];
        };
        try { $this->service->procesar($this->datos()); self::fail('Asiento cuadrado pero incompleto.'); }
        catch (ContabilidadException $e) { self::assertStringContainsString('asiento no concilia', $e->getMessage()); }
        self::assertEquals([900, 900], $observed);
        self::assertSame($before, $this->db->rows); self::assertSame(0, $this->db->transactionLevel());
        self::assertContains('rollback:2', $this->db->trace); self::assertContains('rollback:1', $this->db->trace);
    }

    private function calculator(int $id, string $code, string $type, string $calculator, $value = 0): void
    {
        $this->db->rows['salary_structure_details'][$id] = ['id' => $id, 'salary_structure_id' => 1,
            'codigo' => $code, 'nombre' => $code, 'tipo' => $type, 'calculadora' => $calculator,
            'valor' => $value, 'activo' => true, 'orden' => $id * 10];
    }

    private function sources(string $tip = '20.00', string $advance = '5.00'): void
    {
        $this->db->rows['tips'][1] = ['id' => 1, 'employee_id' => 1, 'fecha' => '2026-11-05', 'estado' => 'pendiente', 'monto' => $tip];
        $this->db->rows['adelantos'][1] = ['id' => 1, 'user_id' => 11];
        $this->db->rows['adelanto_cuotas'][1] = ['id' => 1, 'adelanto_id' => 1, 'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-11-05', 'estado' => 'pendiente', 'monto' => $advance, 'monto_pagado' => '0.00'];
    }

    public function test_real_calculators_reconcile_frozen_commission_extra_tip_legal_deduction_and_advance(): void
    {
        $this->calculator(3, 'HORAS_EXT', 'ingreso', 'horas_extras', 2);
        $this->calculator(4, 'COM_VENT', 'ingreso', 'comisiones');
        $this->calculator(5, 'PROPINAS', 'ingreso', 'propinas');
        $this->calculator(6, 'INSS', 'deduccion', 'inss');
        $this->calculator(7, 'TARDANZAS', 'deduccion', 'tardanzas', '3.21');
        $this->calculator(8, 'ADELANTOS', 'deduccion', 'adelantos');
        $this->db->rows['attendances'][1] = ['id' => 1, 'user_id' => 11, 'date' => '2026-11-05', 'horas_extras' => 2, 'minutos_tardanza' => 1];
        $this->db->rows['comisiones_generadas'][1] = ['id' => 1, 'empleado_id' => 11,
            'fecha' => '2026-11-05 12:00:00', 'estado' => 'pendiente', 'monto_comision' => '12.34'];
        $this->sources();
        $run = $this->service->procesar($this->datos());
        self::assertSame('1049.01', $run->total_ingresos); self::assertSame('80.24', $run->total_deducciones);
        self::assertSame('968.77', $run->total_neto);
        $fund = array_values(array_filter($this->db->rows['detalle_asientos'], fn ($r) => $r['cuenta_id'] === 1121));
        self::assertSame('948.77', $fund[0]['haber']);
        self::assertSame('pagada', $this->db->rows['tips'][1]['estado']);
        self::assertSame('pagada', $this->db->rows['comisiones_generadas'][1]['estado']);
        self::assertEquals(5, $this->db->rows['adelanto_cuotas'][1]['monto_pagado']);
    }

    public function test_real_custody_absorbs_deficit_without_inventing_a_second_payment(): void
    {
        $this->db->rows['employees'][1]['salario_base'] = '10.00';
        $this->calculator(3, 'PROPINAS', 'ingreso', 'propinas');
        $this->calculator(4, 'ADELANTOS', 'deduccion', 'adelantos'); $this->sources('100.00', '80.00');
        $run = $this->service->procesar($this->datos()); self::assertSame('30.00', $run->total_neto);
        $lines = $this->db->rows['detalle_asientos'];
        self::assertSame([], array_values(array_filter($lines, fn ($r) => $r['cuenta_id'] === 1121)));
        $cash = array_values(array_filter($lines, fn ($r) => $r['cuenta_id'] === 111));
        self::assertSame('70.00', $cash[0]['debe']);
        $custody = array_values(array_filter($lines, fn ($r) => $r['cuenta_id'] === 1113));
        self::assertSame('100.00', $custody[0]['haber']);
    }

    public function test_annulment_uses_original_entry_after_structure_becomes_unsupported(): void
    {
        $run = $this->service->procesar($this->datos());
        $original = $this->db->rows['asientos_contables'][1];
        $originalLines = array_values($this->db->rows['detalle_asientos']);
        $this->fixed('BONO_NUEVO', 'ingreso', '100.00');
        $this->service->anular($run, 'Corrección sintética H08');
        self::assertSame($original, $this->db->rows['asientos_contables'][1]);
        $reverse = array_values(array_filter($this->db->rows['detalle_asientos'], fn ($r) => $r['asiento_id'] === 2));
        self::assertCount(count($originalLines), $reverse);
        foreach ($originalLines as $i => $line) {
            self::assertSame($line['cuenta_id'], $reverse[$i]['cuenta_id']);
            self::assertSame($line['debe'], $reverse[$i]['haber']); self::assertSame($line['haber'], $reverse[$i]['debe']);
        }
        self::assertSame('anulada', $this->db->rows['salary_slips'][1]['estado']);
    }
}

/** Cuentas técnicas sintéticas de los mismos códigos operativos; no aprobación AC-05. */
final class H08Accounting extends ContabilidadService
{
    protected function resolverPeriodo($fecha): object { return (object)['id' => 1]; }
    protected function getMonedaBaseId(): int { return 1; }
    protected function getCuentaIdByCodigo(string $codigo): int
    {
        return ['6.7' => 67, '5.3' => 53, '2.1.6' => 216, '1.1.6' => 116, '2.1.5' => 215,
            '1.1.1.3' => 1113, '1.1.1' => 111, '1.1.2.1' => 1121][$codigo];
    }
}
