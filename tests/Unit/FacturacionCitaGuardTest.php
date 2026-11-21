<?php

namespace Tests\Unit;

use App\Exceptions\ContabilidadException;
use App\Models\Cita;
use App\Services\AgendaService;
use App\Services\AprobacionService;
use Illuminate\Config\Repository;
use Illuminate\Database\{ConnectionResolver, MySqlConnection};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\{Collection, Facades\Facade};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Guardia real de agenda con mutex y SELECT simulados, sin PDO ni locks InnoDB. */
final class FacturacionCitaGuardTest extends TestCase
{
    private H06SalesSpy $db;
    private H06AgendaFixture $agenda;
    private $previousResolver;

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('env', 'local'); // V2 expresamente desactivado; no bootstrap empresarial.
        $app->instance('config', new Repository(['salon' => ['inventory_fractional_v2' => false]]));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = new H06SalesSpy;
        $app->instance('db', $this->db);
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['mysql' => $this->db]); $resolver->setDefaultConnection('mysql');
        Model::setConnectionResolver($resolver);
        $this->agenda = new H06AgendaFixture;
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver); else Model::unsetConnectionResolver();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); \Illuminate\Container\Container::setInstance(null);
    }

    public static function applied(): array { return [['pagada'], ['emitida']]; }

    #[DataProvider('applied')]
    public function test_applied_invoice_blocks_second_billing_also_with_v2_disabled(string $status): void
    {
        $this->db->sales = [['id' => 9, 'appointment_id' => 7, 'status' => $status]];
        $this->expectException(ContabilidadException::class);
        $this->agenda->validarParaFacturar([7]);
    }

    public static function unapplied(): array { return [['borrador'], ['pendiente_aprobacion'], ['anulada']]; }

    #[DataProvider('unapplied')]
    public function test_document_without_applied_effects_is_not_automatically_an_invoice(string $status): void
    {
        $this->db->sales = [['id' => 9, 'appointment_id' => 7, 'status' => $status]];
        self::assertSame(7, $this->agenda->validarParaFacturar([7])->get(7)->id);
    }

    public function test_own_current_document_is_excluded_but_not_another_invoice(): void
    {
        $this->db->sales = [['id' => 9, 'appointment_id' => 7, 'status' => 'pagada']];
        self::assertSame(7, $this->agenda->validarParaFacturar([7], 9)->get(7)->id);
        $this->expectException(ContabilidadException::class);
        $this->agenda->validarParaFacturar([7], 10);
    }

    public static function cancelled(): array { return [['cancelada'], ['no_asistio']]; }

    #[DataProvider('cancelled')]
    public function test_non_billable_appointment_state_rejects(string $state): void
    {
        $this->agenda->status = $state;
        $this->expectException(ContabilidadException::class); $this->agenda->validarParaFacturar([7]);
    }

    public function test_current_query_after_simulated_first_commit_blocks_direct_pos_and_second_approval(): void
    {
        self::assertCount(1, $this->agenda->validarParaFacturar([7]));
        $this->db->sales[] = ['id' => 9, 'appointment_id' => 7, 'status' => 'pagada']; // Primera caja confirma.
        foreach ([null, 10] as $otherDocument) {
            try { $this->agenda->validarParaFacturar([7], $otherDocument); self::fail('Duplicó factura.'); }
            catch (ContabilidadException $e) { self::assertStringContainsString('venta aplicada', $e->getMessage()); }
        }
        self::assertStringContainsString('for update', $this->db->queries[0][0]);
        self::assertCount(3, $this->agenda->locks);
    }

    public function test_other_appointment_is_untouched_and_does_not_block_this_one(): void
    {
        $this->db->sales = [['id' => 9, 'appointment_id' => 99, 'status' => 'pagada']];
        $before = $this->db->sales;
        self::assertCount(1, $this->agenda->validarParaFacturar([7]));
        self::assertSame($before, $this->db->sales);
    }

    public function test_new_draft_association_cannot_bypass_applied_invoice_guard(): void
    {
        $this->db->sales = [['id' => 9, 'appointment_id' => 7, 'status' => 'emitida']];
        $this->expectException(ContabilidadException::class); $this->agenda->validarVinculosDocumentales([7]);
    }

    public function test_actual_approval_case_rejects_another_applied_invoice_before_effects(): void
    {
        $this->db->sales = [['id' => 9, 'appointment_id' => 7, 'status' => 'pagada'],
            ['id' => 10, 'appointment_id' => 7, 'status' => 'pendiente_aprobacion']];
        app()->instance(AgendaService::class, $this->agenda);
        app()->instance('db', new class {
            public function transaction($callback) { return $callback(); } // No transacción InnoDB.
        });
        $this->expectException(ContabilidadException::class);
        (new AprobacionService)->aprobarVenta(10, 70);
    }
}

final class H06SalesSpy extends MySqlConnection
{
    public array $sales = [];
    public array $queries = [];
    public function __construct()
    {
        parent::__construct(fn () => throw new \LogicException('PDO prohibido en H06.'), 'h06-memory-only', '', ['name' => 'mysql']);
    }
    public function transactionLevel(): int { return 1; }
    public function select($sql, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->queries[] = [$sql, $bindings];
        if (!str_contains($sql, 'from `sales`')) throw new \LogicException('Consulta no autorizada: '.$sql);
        if (!str_contains($sql, '`appointment_id`')) return array_map(fn ($r) => (object)$r,
            array_values(array_filter($this->sales, fn ($r) => $r['id'] === $bindings[0])));
        $rows = array_filter($this->sales, fn ($r) => $r['appointment_id'] === $bindings[0] && in_array($r['status'], array_slice($bindings, 1, 2), true));
        if (str_contains($sql, '`id` <> ?')) $rows = array_filter($rows, fn ($r) => $r['id'] !== $bindings[count($bindings) - 1]);
        return array_map(fn ($r) => (object)$r, array_values($rows));
    }
}

final class H06AgendaFixture extends AgendaService
{
    public string $status = 'completada';
    public array $locks = [];
    public function bloquearCitas(array $ids, array $estilistasNuevos = []): Collection
    {
        $this->locks[] = ['users', 'appointments', $ids];
        $cita = new Cita; $cita->setRawAttributes(['id' => 7, 'status' => $this->status], true);
        return collect([7 => $cita]);
    }
}
