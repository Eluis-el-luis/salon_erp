<?php

namespace Tests\Unit;

use App\Models\CorridaNomina;
use App\Services\{AccountIntegrityService, ContabilidadService, NominaService};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\{DateFactory, Facades\Facade};
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\H07MemoryConnection;

/** Flujo real con Eloquent y almacenamiento simulado; ninguna conexión SQL. */
final class NominaCoverageTest extends TestCase
{
    private H07MemoryConnection $db;
    private NominaService $service;
    private H07Accounting $accounting;
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
        Model::setConnectionResolver($resolver);
        $app->instance('db', $this->db);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class);
        $auth->shouldReceive('id')->andReturn(99);
        $app->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
        $log = Mockery::mock(); $log->shouldReceive('error')->byDefault(); $app->instance('log', $log);
        // No se evalúa ni aprueba clasificación AC-05 en estas pruebas.
        $integrity = Mockery::mock(AccountIntegrityService::class);
        $integrity->shouldReceive('posting'); $app->instance(AccountIntegrityService::class, $integrity);
        $this->accounting = new H07Accounting;
        $this->service = new NominaService($this->accounting);
        foreach ([1, 2] as $id) {
            $this->db->rows['employees'][$id] = ['id' => $id, 'user_id' => 10 + $id, 'codigo_empleado' => 'EMP-'.$id,
                'salary_structure_id' => 1, 'salario_base' => '1000.00'];
            $this->db->rows['users'][10 + $id] = ['id' => 10 + $id, 'name' => 'Sintético '.$id, 'salario_fijo' => 0];
        }
        $this->db->rows['salary_structures'][1] = ['id' => 1, 'activa' => true];
        foreach (['salario_base', 'comisiones', 'propinas', 'adelantos'] as $i => $calculator) {
            $this->db->rows['salary_structure_details'][$i + 1] = ['id' => $i + 1, 'salary_structure_id' => 1,
                'calculadora' => $calculator, 'activo' => true, 'orden' => $i + 1];
        }
        $this->db->rows['comisiones_generadas'][1] = ['id' => 1, 'empleado_id' => 11,
            'fecha' => '2026-11-05 12:00:00', 'estado' => 'pendiente', 'monto_comision' => '100.00'];
        $this->db->rows['tips'][1] = ['id' => 1, 'employee_id' => 1, 'fecha' => '2026-11-05', 'estado' => 'pendiente', 'monto' => '50.00'];
        $this->db->rows['adelantos'][1] = ['id' => 1, 'user_id' => 11];
        $this->db->rows['adelanto_cuotas'][1] = ['id' => 1, 'adelanto_id' => 1, 'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-11-05', 'estado' => 'pendiente', 'monto' => '100.00', 'monto_pagado' => '0.00'];
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

    private function active(string $start = '2026-11-01', string $end = '2026-11-15', string $status = 'pagada'): void
    {
        $this->db->rows['salary_slips'][50] = ['id' => 50, 'employee_id' => 1,
            'periodo_inicio' => $start, 'periodo_fin' => $end, 'estado' => $status];
    }

    private function reject(callable $action, string $text): void
    {
        $before = $this->db->rows;
        try { $action(); self::fail('Debió rechazar.'); }
        catch (\Exception $e) { self::assertStringContainsString($text, $e->getMessage()); }
        self::assertSame($before, $this->db->rows, 'El rechazo conserva toda la historia del doble.');
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_first_ordinary_run_preserves_sources_and_accounting_contract(): void
    {
        $before = $this->db->rows;
        $preview = $this->service->previsualizar($this->datos());
        self::assertSame($before, $this->db->rows);
        self::assertSame(1050.0, $preview['totales']['neto']);
        $run = $this->service->procesar($this->datos());
        self::assertSame('contabilizada', $run->estado);
        self::assertCount(1, $this->db->rows['salary_slips']);
        self::assertSame('pagada', $this->db->rows['salary_slips'][1]['estado']);
        self::assertSame('pagada', $this->db->rows['comisiones_generadas'][1]['estado']);
        self::assertSame('pagada', $this->db->rows['tips'][1]['estado']);
        self::assertEquals(100, $this->db->rows['adelanto_cuotas'][1]['monto_pagado']);
        self::assertSame('NOM-000001', $this->db->rows['asientos_contables'][1]['numero_asiento']);
        self::assertSame(1, $this->db->rows['asientos_contables'][1]['referencia_id']);
        self::assertSame(1, $this->db->rows['asientos_contables'][1]['periodo_id']);
        self::assertSame(0, $this->db->transactionLevel());
        self::assertSame($before['employees'], $this->db->rows['employees']);
        self::assertSame($before['payrolls'], $this->db->rows['payrolls']);
    }

    public static function overlaps(): array
    {
        return [['2026-11-01', '2026-11-15'], ['2026-11-10', '2026-11-20'],
            ['2026-11-03', '2026-11-08'], ['2026-10-20', '2026-11-30'], ['2026-11-15', '2026-11-30']];
    }

    #[DataProvider('overlaps')]
    public function test_inclusive_overlap_rejects_before_effects(string $start, string $end): void
    {
        $this->active();
        $this->reject(fn () => $this->service->procesar($this->datos(['periodo_inicio' => $start, 'periodo_fin' => $end])), 'cobertura');
        self::assertNotContains('insert:payroll_runs', $this->db->trace);
        self::assertTrue((bool)array_filter($this->db->queries, fn ($q) => str_contains($q[0], 'from `salary_slips`') && str_contains($q[0], 'for update')));
    }

    public function test_repeat_request_is_rejected_rather_than_idempotent_response(): void
    {
        $this->service->procesar($this->datos());
        $this->reject(fn () => $this->service->procesar($this->datos()), 'cobertura');
        self::assertCount(1, $this->db->rows['asientos_contables']);
        self::assertCount(1, $this->db->rows['salary_slips']);
    }

    public function test_contiguous_period_and_other_employee_are_allowed(): void
    {
        $this->service->procesar($this->datos());
        $this->service->procesar($this->datos(['periodo_inicio' => '2026-11-16', 'periodo_fin' => '2026-11-30']));
        $this->service->procesar($this->datos(['empleado_ids' => [2]]));
        self::assertCount(3, $this->db->rows['salary_slips']);
        self::assertCount(3, $this->db->rows['asientos_contables']);
    }

    public function test_valid_annulment_keeps_originals_reopens_sources_and_allows_reprocessing(): void
    {
        $run = $this->service->procesar($this->datos());
        $this->service->anular($run, 'Corrección autorizada sintética');
        self::assertSame('anulada', $this->db->rows['salary_slips'][1]['estado']);
        self::assertSame('anulada', $this->db->rows['payroll_runs'][1]['estado']);
        self::assertSame('pendiente', $this->db->rows['comisiones_generadas'][1]['estado']);
        self::assertSame('pendiente', $this->db->rows['tips'][1]['estado']);
        self::assertEquals(0, $this->db->rows['adelanto_cuotas'][1]['monto_pagado']);
        self::assertSame('REV-NOM-000001', $this->db->rows['asientos_contables'][2]['numero_asiento']);
        $this->service->procesar($this->datos());
        self::assertCount(2, $this->db->rows['salary_slips']);
        self::assertCount(3, $this->db->rows['asientos_contables']);
        self::assertSame('anulada', $this->db->rows['salary_slips'][1]['estado']);
        self::assertSame(0, $this->db->transactionLevel());
    }

    public static function invalidRunStates(): array { return [['borrador'], ['anulada']]; }

    #[DataProvider('invalidRunStates')]
    public function test_annulment_rechecks_fresh_run_state_after_employee_lock(string $state): void
    {
        $stale = $this->service->procesar($this->datos());
        $this->db->rows['payroll_runs'][1]['estado'] = $state;
        $this->db->queries = [];
        $this->reject(fn () => $this->service->anular($stale, 'Motivo sintético'), 'contabilizadas');
        $locks = array_values(array_filter($this->db->queries, fn ($q) => str_contains($q[0], 'for update')));
        self::assertStringContainsString('from `employees`', $locks[0][0]);
        self::assertStringContainsString('from `payroll_runs`', $locks[1][0]);
    }

    public function test_draft_slip_blocks_and_missing_employee_rejects_entire_selection(): void
    {
        $this->active(status: 'borrador');
        $this->reject(fn () => $this->service->procesar($this->datos(['empleado_ids' => [2, 1]])), 'cobertura');
        $this->reject(fn () => $this->service->procesar($this->datos(['empleado_ids' => [2, 999]])), 'no existe');
        self::assertSame([], $this->db->rows['payroll_runs']);
    }

    public function test_extraordinary_adjustment_is_not_an_existing_wizard_operation(): void
    {
        $this->reject(fn () => $this->service->procesar($this->datos(['tipo_nomina' => 'extraordinaria'])), 'motivo y origen');
        self::assertSame([], $this->db->queries);
        // No se afirma soporte de un ajuste legítimo: falta ese contrato persistente.
    }

    public static function legacyStates(): array { return [['pagado', true], ['pagada', true], ['borrador', false]]; }

    #[DataProvider('legacyStates')]
    public function test_legacy_paid_history_remains_read_only(string $state, bool $blocks): void
    {
        $this->db->rows['payrolls'][9] = ['id' => 9, 'user_id' => 11, 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'status' => $state];
        $before = $this->db->rows['payrolls'];
        if ($blocks) $this->reject(fn () => $this->service->procesar($this->datos()), 'legacy');
        else $this->service->procesar($this->datos());
        self::assertSame($before, $this->db->rows['payrolls']);
    }

    public function test_used_commissions_tips_and_advances_are_not_reused(): void
    {
        $this->db->rows['comisiones_generadas'][1]['estado'] = 'pagada';
        $this->db->rows['tips'][1]['estado'] = 'pagada';
        $this->db->rows['adelanto_cuotas'][1]['estado'] = 'pagada';
        $this->db->rows['adelanto_cuotas'][1]['monto_pagado'] = '100.00';
        $data = $this->service->previsualizar($this->datos())['colillas'][0];
        self::assertSame([], $data['comisiones_ids']); self::assertSame([], $data['propinas_ids']);
        self::assertSame([], $data['amortizaciones']); self::assertSame(1000.0, $data['neto_pagar']);
    }

    public static function partialFailures(): array { return [['salary_slip_details'], ['asientos_contables']]; }

    #[DataProvider('partialFailures')]
    public function test_throwable_after_slip_or_accounting_restores_memory_double(string $table): void
    {
        $before = $this->db->rows;
        $this->db->failAfterInsert = $table;
        try { $this->service->procesar($this->datos()); self::fail('Falta centinela.'); }
        catch (\Error $e) { self::assertSame('h07.after.'.$table, $e->getMessage()); }
        self::assertContains('insert:salary_slips', $this->db->trace);
        self::assertContains('insert:'.$table, $this->db->trace);
        self::assertSame($before, $this->db->rows);
        self::assertSame(0, $this->db->transactionLevel());
        if ($table === 'asientos_contables') self::assertContains('rollback:2', $this->db->trace);
    }

    public function test_error_after_reversal_accounting_restores_original_sources_and_coverage(): void
    {
        $run = $this->service->procesar($this->datos()); $before = $this->db->rows;
        $this->db->failAfterInsert = 'asientos_contables';
        try { $this->service->anular($run, 'Motivo sintético'); self::fail('Falta centinela.'); }
        catch (\Error $e) { self::assertSame('h07.after.asientos_contables', $e->getMessage()); }
        self::assertSame($before, $this->db->rows); self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_error_after_complete_balanced_entry_rolls_back_both_transaction_levels(): void
    {
        $before = $this->db->rows;
        $this->accounting->failAfterBalance = true;
        try { $this->service->procesar($this->datos()); self::fail('Falta centinela.'); }
        catch (\Error $e) { self::assertSame('h07.after.balanced_entry', $e->getMessage()); }
        self::assertSame([1], $this->accounting->balancedEntries);
        self::assertContains('insert:detalle_asientos', $this->db->trace);
        self::assertContains('rollback:2', $this->db->trace);
        self::assertContains('rollback:1', $this->db->trace);
        self::assertSame($before, $this->db->rows); self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_one_cent_accounting_discrepancy_aborts_entire_run(): void
    {
        $this->db->unbalancedAggregate = true; // Fallo de lectura agregado del doble, sin cambiar reglas económicas.
        $this->reject(fn () => $this->service->procesar($this->datos()), 'descuadrado');
        self::assertContains('insert:detalle_asientos', $this->db->trace);
        self::assertContains('rollback:2', $this->db->trace);
    }

    public function test_second_annulment_does_not_release_sources_twice(): void
    {
        $stale = $this->service->procesar($this->datos());
        $this->service->anular($stale, 'Corrección sintética');
        $this->reject(fn () => $this->service->anular($stale, 'Reenvío sintético'), 'contabilizadas');
        self::assertCount(2, $this->db->rows['asientos_contables']);
    }

    public function test_annulment_cannot_release_coverage_of_paid_slip_without_accounting_reference(): void
    {
        $run = $this->service->procesar($this->datos());
        $this->db->rows['salary_slips'][1]['asiento_contable_id'] = null;
        $this->reject(fn () => $this->service->anular($run, 'Motivo sintético'), 'incompatibles');
        self::assertSame('pagada', $this->db->rows['salary_slips'][1]['estado']);
        self::assertSame('pagada', $this->db->rows['tips'][1]['estado']);
    }

    public function test_preview_is_advisory_and_still_rejects_existing_overlap_without_locks(): void
    {
        $this->active();
        $this->reject(fn () => $this->service->previsualizar($this->datos()), 'cobertura');
        self::assertFalse((bool)array_filter($this->db->queries, fn ($q) => str_contains($q[0], 'for update')));
    }

    public function test_lock_order_is_numeric_and_all_employees_are_locked_before_coverage(): void
    {
        $this->service->procesar($this->datos(['empleado_ids' => [2, 1, 2]]));
        $locks = array_values(array_filter($this->db->queries, fn ($q) => str_contains($q[0], 'for update')));
        self::assertStringContainsString('from `employees`', $locks[0][0]); self::assertSame([1], $locks[0][1]);
        self::assertStringContainsString('from `employees`', $locks[1][0]); self::assertSame([2], $locks[1][1]);
        self::assertStringContainsString('from `salary_slips`', $locks[2][0]);
        self::assertCount(2, $this->db->rows['salary_slips']);
        // Son llamadas y orden de lectura; NO dos conexiones InnoDB simultáneas.
    }

    public function test_simulated_interleaving_rechecks_coverage_after_other_commit_or_annulment(): void
    {
        $this->service->previsualizar($this->datos()); // B vio que no había cobertura.
        $runA = $this->service->procesar($this->datos()); // A confirma primero.
        $this->reject(fn () => $this->service->procesar($this->datos()), 'cobertura');
        $this->service->anular($runA, 'Motivo sintético'); // A libera tras confirmar.
        $this->service->procesar($this->datos());
        self::assertCount(2, $this->db->rows['salary_slips']);
    }
}

/** Sustituye únicamente búsquedas de configuración, conserva el asiento y validarCuadre reales. */
final class H07Accounting extends ContabilidadService
{
    public bool $failAfterBalance = false;
    public array $balancedEntries = [];
    protected function resolverPeriodo($fecha): object { return (object)['id' => 1]; }
    protected function getMonedaBaseId(): int { return 1; }
    protected function getCuentaIdByCodigo(string $codigo): int
    {
        return ['6.7' => 67, '5.3' => 53, '2.1.6' => 216, '1.1.6' => 116, '2.1.5' => 215,
            '1.1.1.3' => 1113, '1.1.1' => 111, '1.1.2.1' => 1121][$codigo];
    }
    public function validarCuadre($asientoId, ?int $originalId = null)
    {
        parent::validarCuadre($asientoId, $originalId);
        $this->balancedEntries[] = $asientoId;
        if ($this->failAfterBalance) throw new \Error('h07.after.balanced_entry');
    }
}
