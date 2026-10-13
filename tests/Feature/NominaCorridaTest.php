<?php

namespace Tests\Feature;

use App\Models\Adelanto;
use App\Models\AdelantoCuota;
use App\Models\Ausencia;
use App\Models\ComisionGenerada;
use App\Models\CorridaNomina;
use App\Models\DetalleColilla;
use App\Models\Empleado;
use App\Models\EstructuraSalarial;
use App\Models\Propina;
use App\Models\Rol;
use App\Models\TipoAusencia;
use App\Models\Usuario;
use App\Services\NominaService;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\CuentasComplementariasSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MOTOR DE NÓMINA CORPORATIVA (HRMS):
 *  - Cuadre exacto del asiento NOM-* por colilla (partida doble, sin tolerancia).
 *  - Cierre de comisiones congeladas, propinas y amortización de adelantos.
 *  - CAP: la amortización nunca deja la colilla en negativo.
 *  - Anulación con asientos espejo REV-* y reapertura de comisiones/cuotas/propinas.
 *  - Fail-safe: deducciones mayores a ingresos abortan la corrida.
 */
class NominaCorridaTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $admin;

    protected Empleado $expediente;

    protected NominaService $nomina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ContabilidadBaseSeeder::class);
        $this->seed(CuentasComplementariasSeeder::class);

        $this->admin = Usuario::create([
            'name' => 'Admin',
            'email' => 'admin@t.com',
            'password' => 'password',
            'role' => 'admin',
            'role_id' => Rol::where('name', 'Administrador')->first()->id,
            'is_active' => true,
        ]);

        $colaborador = Usuario::create([
            'name' => 'Estilista Uno',
            'email' => 'estilista@t.com',
            'password' => 'password',
            'role' => 'estilista',
            'role_id' => Rol::where('name', 'Estilista')->first()->id,
            'salario_fijo' => 10000,
            'is_active' => true,
        ]);

        $this->expediente = Empleado::crearParaUsuario($colaborador, [
            'salario_base' => 10000,
            'fecha_ingreso' => '2026-01-01',
        ]);

        $this->nomina = app(NominaService::class);
        $this->actingAs($this->admin);
    }

    /** Período estándar: 2026-10-01 al 2026-10-15. */
    protected function datosProcesar(array $extras = []): array
    {
        return array_merge([
            'periodo_inicio' => '2026-10-01',
            'periodo_fin' => '2026-10-15',
            'metodo_pago' => 'banco',
            'empleado_ids' => [$this->expediente->id],
        ], $extras);
    }

    /** Crea una venta mínima para satisfacer la FK de comisiones_generadas. */
    protected function crearVenta(int $total): int
    {
        return DB::table('sales')->insertGetId([
            'cashier_id' => $this->expediente->user_id,
            'subtotal' => $total,
            'total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function crearComision(float $montoComision): ComisionGenerada
    {
        return ComisionGenerada::create([
            'empleado_id' => $this->expediente->user_id,
            'venta_id' => $this->crearVenta($montoComision > 0 ? 10000 : 0),
            'monto_venta' => 10000,
            'porcentaje_aplicado' => 30,
            'monto_comision' => $montoComision,
            'fecha' => '2026-10-05',
            'estado' => 'pendiente',
        ]);
    }

    protected function crearAdelantoConCuota(float $monto, string $vencimiento): AdelantoCuota
    {
        $adelanto = Adelanto::create([
            'user_id' => $this->expediente->user_id,
            'date' => '2026-10-01',
            'amount' => $monto,
            'type' => 'debe',
            'description' => 'Adelanto de salario (test)',
        ]);

        return AdelantoCuota::create([
            'adelanto_id' => $adelanto->id,
            'numero_cuota' => 1,
            'fecha_vencimiento' => $vencimiento,
            'monto' => $monto,
            'estado' => 'pendiente',
            'monto_pagado' => 0,
        ]);
    }

    protected function crearPropina(float $monto): Propina
    {
        return Propina::create([
            'employee_id' => $this->expediente->id,
            'fecha' => '2026-10-05',
            'monto' => $monto,
            'estado' => 'pendiente',
        ]);
    }

    protected function totalDebeHaber(int $asientoId): array
    {
        $totales = DB::table('detalle_asientos')
            ->where('asiento_id', $asientoId)
            ->selectRaw('SUM(debe) as debe, SUM(haber) as haber')
            ->first();

        return [(float) $totales->debe, (float) $totales->haber];
    }

    protected function montoCuenta(int $asientoId, string $codigo, string $columna): float
    {
        $linea = DB::table('detalle_asientos')
            ->join('cuentas_contables', 'cuentas_contables.id', '=', 'detalle_asientos.cuenta_id')
            ->where('detalle_asientos.asiento_id', $asientoId)
            ->where('cuentas_contables.codigo', $codigo)
            ->sum($columna);

        return (float) $linea;
    }

    // =====================================================================
    // CUADRE EXACTO Y ESTRUCTURA DEL ASIENTO
    // =====================================================================

    public function test_la_corrida_contabiliza_con_cuadre_exacto_y_cuentas_correctas(): void
    {
        // Fuentes del período
        $this->crearComision(3000);
        $propina = $this->crearPropina(500);
        $cuota = $this->crearAdelantoConCuota(2000, '2026-10-10');

        $corrida = $this->nomina->procesar($this->datosProcesar());

        // Estado de la corrida
        $this->assertEquals('contabilizada', $corrida->fresh()->estado);

        // Colilla: ingresos 10000 + 3000 + 500 = 13500; deducciones INSS 7% de 13000 = 910 + adelanto 2000
        $colilla = $corrida->colillas()->first();
        $this->assertEquals(13500.0, (float) $colilla->total_ingresos);
        $this->assertEquals(2910.0, (float) $colilla->total_deducciones);
        $this->assertEquals(10590.0, (float) $colilla->neto_pagar);

        // Asiento cuadrado a centavos exactos
        [$debe, $haber] = $this->totalDebeHaber($colilla->asiento_contable_id);
        $this->assertSame($debe, $haber, 'El asiento NOM- no cuadra: debe '.$debe.' vs haber '.$haber);
        $this->assertEquals(13500.0, $debe);

        // Estructura del asiento
        $this->assertEquals(10000.0, $this->montoCuenta($colilla->asiento_contable_id, '6.7', 'debe'), 'Gasto de sueldos 6.7');
        $this->assertEquals(3000.0, $this->montoCuenta($colilla->asiento_contable_id, '5.3', 'debe'), 'Gasto de comisiones 5.3');
        $this->assertEquals(500.0, $this->montoCuenta($colilla->asiento_contable_id, '2.1.6', 'debe'), 'Extinción pasivo de propinas 2.1.6');
        $this->assertEquals(2000.0, $this->montoCuenta($colilla->asiento_contable_id, '1.1.6', 'haber'), 'Amortización de anticipos 1.1.6');
        $this->assertEquals(910.0, $this->montoCuenta($colilla->asiento_contable_id, '2.1.5', 'haber'), 'Retenciones por pagar 2.1.5');
        $this->assertEquals(500.0, $this->montoCuenta($colilla->asiento_contable_id, '1.1.1.3', 'haber'), 'Liberación de custodia 1.1.1.3');
        $this->assertEquals(10090.0, $this->montoCuenta($colilla->asiento_contable_id, '1.1.2.1', 'haber'), 'Pago neto por banco 1.1.2.1');

        // Cierre de fuentes
        $this->assertEquals('pagada', $cuota->fresh()->estado);
        $this->assertEquals(2000.0, (float) $cuota->fresh()->monto_pagado);
        $this->assertEquals('pagada', $propina->fresh()->estado);
        $this->assertEquals($colilla->id, $propina->fresh()->salary_slip_id);
        $this->assertEquals('pagada', ComisionGenerada::first()->fresh()->estado);
        $this->assertEquals($corrida->id, ComisionGenerada::first()->fresh()->periodo_nomina_id);
        $this->assertEquals($colilla->id, ComisionGenerada::first()->fresh()->salary_slip_id);
    }

    // =====================================================================
    // CAP: LA AMORTIZACIÓN NUNCA DEJA LA COLILLA EN NEGATIVO
    // =====================================================================

    public function test_el_adelanto_se_amortiza_solo_hasta_el_disponible_y_queda_parcial(): void
    {
        $cuota = $this->crearAdelantoConCuota(20000, '2026-10-10');

        $corrida = $this->nomina->procesar($this->datosProcesar());
        $colilla = $corrida->colillas()->first();

        // Ingresos 10000 (solo salario base): INSS 700 + adelanto cap 9300 = 10000 → neto 0
        $this->assertEquals(10000.0, (float) $colilla->total_ingresos);
        $this->assertEquals(0.0, (float) $colilla->neto_pagar);

        // La cuota queda parcial, no pagada
        $this->assertEquals('parcial', $cuota->fresh()->estado);
        $this->assertEquals(9300.0, (float) $cuota->fresh()->monto_pagado);

        // El asiento cuadra igualmente
        [$debe, $haber] = $this->totalDebeHaber($colilla->asiento_contable_id);
        $this->assertSame($debe, $haber);
        $this->assertEquals(10000.0, $debe);
    }

    public function test_el_deficit_laboral_se_absorbe_con_propinas_custodiadas_y_cuadra(): void
    {
        $this->crearPropina(5000);
        $this->crearAdelantoConCuota(20000, '2026-10-10');

        $corrida = $this->nomina->procesar($this->datosProcesar());
        $colilla = $corrida->colillas()->first();

        // Ingresos 15000 (base 10000 + propinas 5000); INSS 700; cap adelantos 14300; neto 0
        $this->assertEquals(15000.0, (float) $colilla->total_ingresos);
        $this->assertEquals(0.0, (float) $colilla->neto_pagar);

        [$debe, $haber] = $this->totalDebeHaber($colilla->asiento_contable_id);
        $this->assertSame($debe, $haber, 'La absorción del déficit con custodia debe cuadrar');
        $this->assertEquals(20000.0, $debe);

        // La custodia se libera completa y la caja absorbe el déficit de 5000
        $this->assertEquals(5000.0, $this->montoCuenta($colilla->asiento_contable_id, '1.1.1.3', 'haber'));
        $this->assertEquals(5000.0, $this->montoCuenta($colilla->asiento_contable_id, '1.1.1', 'debe'));
        $this->assertEquals(0.0, $this->montoCuenta($colilla->asiento_contable_id, '1.1.2.1', 'haber'), 'No sale dinero del banco cuando el déficit lo cubren las propinas');
    }

    // =====================================================================
    // AUSENCIAS SIN GOCE: PRORRATEO DEL SALARIO BASE
    // =====================================================================

    public function test_las_ausencias_sin_goce_prorratean_el_salario_base(): void
    {
        $this->crearComision(3000);

        $tipoPSS = TipoAusencia::where('codigo', 'PSS')->firstOrFail();
        Ausencia::create([
            'employee_id' => $this->expediente->id,
            'leave_type_id' => $tipoPSS->id,
            'fecha_inicio' => '2026-10-05',
            'fecha_fin' => '2026-10-07',
            'dias' => 3,
            'estado' => 'aprobada',
            'revisado_por' => $this->admin->id,
            'fecha_revision' => now(),
        ]);

        $corrida = $this->nomina->procesar($this->datosProcesar());
        $colilla = $corrida->colillas()->first();

        // Período de 15 días: sin goce = 10000 / 15 * 3 = 2000; INSS 7% de 13000 = 910
        $this->assertEquals(13000.0, (float) $colilla->total_ingresos);
        $this->assertEquals(2910.0, (float) $colilla->total_deducciones);
        $this->assertEquals(10090.0, (float) $colilla->neto_pagar);

        $sinGoce = DetalleColilla::where('salary_slip_id', $colilla->id)->where('codigo', 'SIN_GOCE')->first();
        $this->assertNotNull($sinGoce, 'Debe existir la línea de ausencias sin goce');
        $this->assertEquals(2000.0, (float) $sinGoce->monto);

        // El gasto de sueldos del asiento se reduce proporcionalmente (10000 - 2000)
        $this->assertEquals(8000.0, $this->montoCuenta($colilla->asiento_contable_id, '6.7', 'debe'));
    }

    // =====================================================================
    // FAIL-SAFE: DEDUCCIONES MAYORES A INGRESOS
    // =====================================================================

    public function test_deducciones_mayores_a_ingresos_abortan_la_corrida(): void
    {
        // Estructura con deducción fija excesiva
        $estructura = EstructuraSalarial::create(['nombre' => 'Test Excesiva', 'activa' => false]);
        $estructura->detalles()->createMany([
            ['codigo' => 'SAL_BASE', 'nombre' => 'Salario', 'tipo' => 'ingreso', 'calculadora' => 'salario_base', 'valor' => 0, 'orden' => 10, 'activo' => true],
            ['codigo' => 'MULTA', 'nombre' => 'Multa excesiva', 'tipo' => 'deduccion', 'calculadora' => 'fijo', 'valor' => 50000, 'orden' => 200, 'activo' => true],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('exceden los ingresos');

        $this->nomina->procesar($this->datosProcesar(['estructura_id' => $estructura->id]));

        // Nada debe haberse persistido
        $this->assertEquals(0, CorridaNomina::count());
    }

    // =====================================================================
    // ANULACIÓN CON REVERSIÓN
    // =====================================================================

    public function test_la_anulacion_genera_rev_y_reabre_comisiones_cuotas_y_propinas(): void
    {
        $this->crearComision(3000);
        $this->crearPropina(500);
        $cuota = $this->crearAdelantoConCuota(2000, '2026-10-10');

        $corrida = $this->nomina->procesar($this->datosProcesar());
        $colilla = $corrida->colillas()->first();
        $asientoOriginal = $colilla->asiento->numero_asiento;

        $this->nomina->anular($corrida, 'Error de período duplicado');

        // Corrida y colilla anuladas
        $this->assertEquals('anulada', $corrida->fresh()->estado);
        $this->assertEquals('anulada', $colilla->fresh()->estado);

        // Asiento espejo REV-*
        $reversa = DB::table('asientos_contables')
            ->where('numero_asiento', 'REV-'.$asientoOriginal)
            ->first();
        $this->assertNotNull($reversa, 'Debe existir el asiento espejo REV-*');
        [$debeRev, $haberRev] = $this->totalDebeHaber($reversa->id);
        $this->assertSame($debeRev, $haberRev);
        $this->assertEquals(13500.0, $debeRev);

        // Reapertura de fuentes
        $this->assertEquals('pendiente', ComisionGenerada::first()->fresh()->estado);
        $this->assertNull(ComisionGenerada::first()->fresh()->salary_slip_id);
        $this->assertEquals('pendiente', $cuota->fresh()->estado);
        $this->assertEquals(0.0, (float) $cuota->fresh()->monto_pagado);
        $this->assertEquals('pendiente', Propina::first()->fresh()->estado);
        $this->assertNull(Propina::first()->fresh()->salary_slip_id);
    }

    // =====================================================================
    // PERMISOS: RRHH/NÓMINA ES TERRITORIO EXCLUSIVO DEL ADMIN
    // =====================================================================

    public function test_recepcion_no_accede_a_nomina_ni_rrhh(): void
    {
        $recepcion = Usuario::create([
            'name' => 'Recepcion',
            'email' => 'recep@t.com',
            'password' => 'password',
            'role' => 'recepcion',
            'role_id' => Rol::where('name', 'Recepción / Caja')->first()->id,
            'is_active' => true,
        ]);

        $this->actingAs($recepcion);

        foreach (['/nomina', '/nomina/wizard', '/rrhh', '/ausencias', '/nomina/estructuras'] as $ruta) {
            $this->get($ruta)->assertForbidden('La ruta '.$ruta.' debería estar bloqueada para recepción');
        }
    }
}
