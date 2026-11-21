<?php

namespace Tests\Unit;

use App\Services\{AccountIntegrityService, ContabilidadService, NominaService};
use App\Exceptions\ContabilidadException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\{DateFactory, Facades\Facade};
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\H07MemoryConnection;

/** Servicios reales y doble sin PDO. Ninguna conclusión de concurrencia InnoDB. */
final class NominaOrdenCuotasTest extends TestCase
{
    private H07MemoryConnection $db;
    private NominaService $service;
    private $previousResolver;
    private const CALCULADORAS = [
        'SAL_BASE' => ['salario_base', 'ingreso', 0], 'COM_VENT' => ['comisiones', 'ingreso', 0],
        'PROPINAS' => ['propinas', 'ingreso', 0], 'HORAS_EXT' => ['horas_extras', 'ingreso', 2],
        'INSS' => ['inss', 'deduccion', 0], 'IR' => ['ir', 'deduccion', 0],
        'SIN_GOCE' => ['sin_goce', 'deduccion', 0], 'TARDANZAS' => ['tardanzas', 'deduccion', '3.21'],
        'ADELANTOS' => ['adelantos', 'deduccion', 0],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container; Container::setInstance($app);
        $app->instance('config', new Repository); $app->instance('date', new DateFactory);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = new H07MemoryConnection;
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['mysql' => $this->db]); $resolver->setDefaultConnection('mysql');
        Model::setConnectionResolver($resolver); $app->instance('db', $this->db);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class); $auth->shouldReceive('id')->andReturn(99);
        $app->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
        $log = Mockery::mock(); $log->shouldReceive('error'); $app->instance('log', $log);
        $integrity = Mockery::mock(AccountIntegrityService::class); $integrity->shouldReceive('posting');
        $app->instance(AccountIntegrityService::class, $integrity);
        $this->service = new NominaService(new H11Accounting);
        $this->db->rows['employees'][1] = ['id' => 1, 'user_id' => 11, 'codigo_empleado' => 'H11-1',
            'salary_structure_id' => 1, 'salario_base' => '200000.00'];
        $this->db->rows['users'][11] = ['id' => 11, 'name' => 'H11 sintético', 'salario_fijo' => 0];
        $this->db->rows['salary_structures'][1] = ['id' => 1, 'activa' => true];
        $this->setOrder(array_keys(self::CALCULADORAS));
        $this->db->rows['attendances'][1] = ['id' => 1, 'user_id' => 11, 'date' => '2026-11-05', 'horas_extras' => 2, 'minutos_tardanza' => 1];
        $this->db->rows['comisiones_generadas'][1] = ['id' => 1, 'empleado_id' => 11,
            'fecha' => '2026-11-05 12:00:00', 'estado' => 'pendiente', 'monto_comision' => '250.25'];
        $this->db->rows['tips'][1] = ['id' => 1, 'employee_id' => 1, 'fecha' => '2026-11-05', 'estado' => 'pendiente', 'monto' => '30.50'];
        $this->db->rows['leave_types'][1] = ['id' => 1, 'pagado' => false];
        $this->db->rows['leave_applications'][1] = ['id' => 1, 'employee_id' => 1, 'leave_type_id' => 1,
            'fecha_inicio' => '2026-11-06', 'fecha_fin' => '2026-11-06', 'estado' => 'aprobada'];
        $this->db->rows['adelantos'][1] = ['id' => 1, 'user_id' => 11, 'type' => 'debe'];
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver); else Model::unsetConnectionResolver();
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        parent::tearDown();
    }

    private function setOrder(array $codes, int $structure = 1): void
    {
        foreach ($codes as $i => $code) {
            [$calculator, $type, $value] = self::CALCULADORAS[$code];
            $id = $structure * 100 + array_search($code, array_keys(self::CALCULADORAS), true);
            $this->db->rows['salary_structure_details'][$id] = ['id' => $id, 'salary_structure_id' => $structure,
                'codigo' => $code, 'tipo' => $type, 'calculadora' => $calculator, 'valor' => $value, 'activo' => true, 'orden' => ($i + 1) * 10];
        }
    }

    private function datos(array $extra = []): array
    {
        return array_replace(['periodo_inicio' => '2026-11-01', 'periodo_fin' => '2026-11-15',
            'metodo_pago' => 'banco', 'empleado_ids' => [1]], $extra);
    }

    private function preview(): array { return $this->service->previsualizar($this->datos())['colillas'][0]; }

    private function economic(array $data): array
    {
        $lines = [];
        foreach ($data['lineas'] as $line) $lines[$line['codigo'].':'.($line['referencia_id'] ?? '')] = [$line['tipo'], $line['fuente'], $line['monto']];
        ksort($lines);
        return [$data['total_ingresos'], $data['total_deducciones'], $data['neto_pagar'], $lines, $data['amortizaciones']];
    }

    private function quota(int $id, string $date, string $amount, string $paid = '0.00', string $state = 'pendiente', int $advance = 1): void
    {
        $this->db->rows['adelanto_cuotas'][$id] = ['id' => $id, 'adelanto_id' => $advance, 'numero_cuota' => $id,
            'fecha_vencimiento' => $date, 'estado' => $state, 'monto' => $amount, 'monto_pagado' => $paid];
    }

    public function test_retenciones_before_income_have_the_same_economic_result(): void
    {
        $baseline = $this->economic($this->preview());
        $this->setOrder(['IR', 'INSS', 'TARDANZAS', 'SIN_GOCE', 'HORAS_EXT', 'PROPINAS', 'COM_VENT', 'SAL_BASE', 'ADELANTOS']);
        self::assertSame($baseline, $this->economic($this->preview()));
    }

    public function test_advance_before_legal_deductions_uses_the_final_available(): void
    {
        $this->quota(1, '2026-11-05', '200000.00');
        $baseline = $this->economic($this->preview());
        $this->setOrder(['SAL_BASE', 'COM_VENT', 'PROPINAS', 'HORAS_EXT', 'ADELANTOS', 'INSS', 'IR', 'SIN_GOCE', 'TARDANZAS']);
        self::assertSame($baseline, $this->economic($this->preview()));
    }

    public static function visualOrders(): array
    {
        $codes = array_keys(self::CALCULADORAS); $orders = [];
        foreach ([$codes, array_reverse($codes)] as $direction => $base) {
            for ($i = 0; $i < count($base); ++$i) {
                $orders['direction-'.$direction.'-rotate-'.$i] = [array_merge(array_slice($base, $i), array_slice($base, 0, $i))];
            }
        }
        return $orders;
    }

    #[DataProvider('visualOrders')]
    public function test_visual_permutations_preserve_all_economic_results_and_emit_reconciled_run(array $order): void
    {
        $this->quota(1, '2026-11-05', '1000.00');
        $baseline = $this->economic($this->preview());
        // Misma economía y fuentes; cambia únicamente `orden` en otra estructura.
        $this->db->rows['salary_structures'][2] = ['id' => 2, 'activa' => true];
        $this->setOrder($order, 2);
        $data = $this->service->previsualizar($this->datos(['estructura_id' => 2]))['colillas'][0];
        self::assertSame($baseline, $this->economic($data));
        self::assertSame($order, array_column($data['lineas'], 'codigo'));
        self::assertSame([203614.08, 44124.93, 159489.15], array_slice($baseline, 0, 3));
        $run = $this->service->procesar($this->datos(['estructura_id' => 2]));
        self::assertSame('203614.08', $run->total_ingresos);
        self::assertSame('44124.93', $run->total_deducciones);
        self::assertSame('159489.15', $run->total_neto);
        $lines = array_values($this->db->rows['salary_slip_details']);
        self::assertSame($order, array_column($lines, 'codigo'));
        self::assertSame('contabilizada', $run->estado);
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_tied_visual_positions_still_do_not_control_calculation(): void
    {
        $baseline = $this->economic($this->preview());
        foreach ($this->db->rows['salary_structure_details'] as &$row) $row['orden'] = 0;
        unset($row);
        self::assertSame($baseline, $this->economic($this->preview()));
    }

    public function test_zero_salary_without_other_sources_is_valid_in_reverse_order(): void
    {
        $this->db->rows['employees'][1]['salario_base'] = '0.00';
        foreach (['attendances', 'leave_applications', 'comisiones_generadas', 'tips'] as $table) $this->db->rows[$table] = [];
        $this->setOrder(array_reverse(array_keys(self::CALCULADORAS)));
        $run = $this->service->procesar($this->datos());
        self::assertSame('0.00', $run->total_neto); self::assertSame([], $this->db->rows['salary_slip_details']);
    }

    public function test_invalid_structure_and_duplicate_calculator_remain_blocked_by_h08(): void
    {
        $before = $this->db->rows;
        foreach (['fijo', 'salario_base'] as $calculator) {
            $this->db->rows['salary_structure_details'][999] = ['id' => 999, 'salary_structure_id' => 1,
                'codigo' => 'OTRO', 'tipo' => 'ingreso', 'calculadora' => $calculator, 'activo' => true, 'orden' => 0];
            try { $this->service->procesar($this->datos()); self::fail('H08 se relajó.'); }
            catch (ContabilidadException $e) { self::assertNotEmpty($e->getMessage()); }
        }
        self::assertSame([], $this->db->rows['payroll_runs']);
        self::assertSame($before['adelanto_cuotas'], $this->db->rows['adelanto_cuotas']);
    }

    public function test_structural_deductions_exceeding_income_reject_without_partial_effects(): void
    {
        $this->db->rows['salary_structure_details'][107]['valor'] = '250000.00';
        $before = $this->db->rows;
        try { $this->service->procesar($this->datos()); self::fail('No puede financiar deducciones estructurales con un neto negativo.'); }
        catch (\Exception $e) { self::assertStringContainsString('exceden los ingresos', $e->getMessage()); }
        self::assertSame($before, $this->db->rows); self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_current_window_partials_paid_future_and_other_employee_selection(): void
    {
        $this->quota(1, '2026-11-05', '1000.00', '250.00', 'parcial');
        $this->quota(2, '2026-11-05', '1000.00', '1000.00', 'pagada');
        $this->quota(3, '2026-11-16', '1000.00');
        $this->db->rows['adelantos'][2] = ['id' => 2, 'user_id' => 12, 'type' => 'debe'];
        $this->quota(4, '2026-11-05', '1000.00', advance: 2);
        $this->quota(5, '2026-10-31', '1000.00', '100.00', 'parcial'); // Ampliación bloqueada: contrato aún no definido.
        self::assertSame([1 => 750.0], $this->preview()['amortizaciones']);
        $before = $this->db->rows['adelanto_cuotas'];
        $this->service->procesar($this->datos());
        self::assertEquals(1000, $this->db->rows['adelanto_cuotas'][1]['monto_pagado']);
        foreach ([2, 3, 4, 5] as $id) self::assertSame($before[$id], $this->db->rows['adelanto_cuotas'][$id]);
    }

    public function test_recovery_order_is_due_date_then_id_and_cap_is_final_available(): void
    {
        // Inserción deliberadamente contraria al ORDER BY para comprobar también el doble.
        $this->quota(3, '2026-11-10', '90000.00'); $this->quota(2, '2026-11-05', '90000.00'); $this->quota(1, '2026-11-05', '90000.00');
        self::assertSame([1 => 90000.0, 2 => 70489.15], $this->preview()['amortizaciones']);
        $this->service->procesar($this->datos());
        self::assertSame('pagada', $this->db->rows['adelanto_cuotas'][1]['estado']);
        self::assertSame('parcial', $this->db->rows['adelanto_cuotas'][2]['estado']);
        self::assertSame('pendiente', $this->db->rows['adelanto_cuotas'][3]['estado']);
        $references = array_values(array_filter(array_column($this->db->rows['salary_slip_details'], 'referencia_id')));
        self::assertSame([1, 2], $references);
    }

    public function test_partial_from_previous_period_remains_excluded_pending_policy_characterization(): void
    {
        $this->quota(1, '2026-11-05', '200000.00');
        $this->service->procesar($this->datos());
        $partial = $this->db->rows['adelanto_cuotas'][1];
        self::assertSame('parcial', $partial['estado']);
        self::assertEquals(160489.15, $partial['monto_pagado']);
        $next = $this->service->previsualizar($this->datos(['periodo_inicio' => '2026-11-16', 'periodo_fin' => '2026-11-30']));
        self::assertSame([], $next['colillas'][0]['amortizaciones']);
        self::assertSame($partial, $this->db->rows['adelanto_cuotas'][1]);
        // Evidencia del límite NO resuelto; no es una expectativa económica aprobada ni prueba de continuidad.
    }

    public function test_unlinked_advance_reversal_does_not_identify_non_exigible_quotas_characterization(): void
    {
        $this->quota(1, '2026-11-05', '100.00');
        $this->db->rows['adelantos'][2] = ['id' => 2, 'user_id' => 11, 'type' => 'haber',
            'description' => 'REVERSIÓN AUTOMÁTICA MOV. #1: sintético'];
        self::assertSame([1 => 100.0], $this->preview()['amortizaciones']);
        // No se interpreta texto como vínculo ni se autoriza cobrar un adelanto revertido.
    }

    public function test_retry_annul_and_reprocess_preserve_original_documents_after_visual_change(): void
    {
        $this->quota(1, '2026-11-05', '200000.00');
        $run = $this->service->procesar($this->datos());
        $original = $this->db->rows['salary_slip_details']; $journal = $this->db->rows['detalle_asientos'];
        $beforeRetry = $this->db->rows;
        try { $this->service->procesar($this->datos()); self::fail('H07 debe bloquear el reenvío.'); }
        catch (\Exception $e) { self::assertStringContainsString('cobertura', $e->getMessage()); }
        self::assertSame($beforeRetry, $this->db->rows);
        $this->setOrder(array_reverse(array_keys(self::CALCULADORAS)));
        $this->service->anular($run, 'Reproceso sintético H11');
        self::assertEquals(0, $this->db->rows['adelanto_cuotas'][1]['monto_pagado']);
        self::assertSame('pendiente', $this->db->rows['adelanto_cuotas'][1]['estado']);
        $this->service->procesar($this->datos());
        self::assertEquals(160489.15, $this->db->rows['adelanto_cuotas'][1]['monto_pagado']);
        foreach ($original as $id => $line) self::assertSame($line, $this->db->rows['salary_slip_details'][$id]);
        foreach ($journal as $id => $line) self::assertSame($line, $this->db->rows['detalle_asientos'][$id]);
        self::assertCount(3, $this->db->rows['asientos_contables']);
    }

    public static function failurePoints(): array { return [['payroll_runs'], ['detalle_asientos']]; }

    #[DataProvider('failurePoints')]
    public function test_failures_before_accounting_and_after_accounting_restore_memory_state(string $table): void
    {
        $this->quota(1, '2026-11-05', '1000.00');
        $before = $this->db->rows; $reached = [];
        $this->db->afterInsert = function ($db, $current, $row) use ($table, &$reached) {
            if ($current !== $table) return;
            if ($table === 'detalle_asientos' && $row['cuenta_id'] !== 1121) return;
            $reached = [$db->transactionLevel(), count($db->rows['asientos_contables']), count($db->rows['salary_slip_details'])];
            throw new \Error('h11.sentinel.'.$table);
        };
        try { $this->service->procesar($this->datos()); self::fail('No alcanzó centinela.'); }
        catch (\Error $e) { self::assertSame('h11.sentinel.'.$table, $e->getMessage()); }
        self::assertSame($table === 'payroll_runs' ? [1, 0, 0] : [2, 1, 9], $reached);
        self::assertSame($before, $this->db->rows); self::assertSame(0, $this->db->transactionLevel());
    }
}

final class H11Accounting extends ContabilidadService
{
    protected function resolverPeriodo($fecha): object { return (object)['id' => 1]; }
    protected function getMonedaBaseId(): int { return 1; }
    protected function getCuentaIdByCodigo(string $codigo): int
    {
        return ['6.7' => 67, '5.3' => 53, '2.1.6' => 216, '1.1.6' => 116, '2.1.5' => 215,
            '1.1.1.3' => 1113, '1.1.1' => 111, '1.1.2.1' => 1121][$codigo];
    }
}
