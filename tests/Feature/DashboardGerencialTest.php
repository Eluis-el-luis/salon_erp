<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Rol;
use App\Models\Servicio;
use App\Models\SesionCaja;
use App\Models\Usuario;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\CuentasComplementariasSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DASHBOARD GERENCIAL (Centro de Mando) — arquitectura BI:
 *
 *  1. KPIs leídos del LIBRO MAYOR (detalle_asientos): ingresos = haber clase 4,
 *     costos = debe clase 5, gastos = 6.x, otros egresos = 7.x. Los asientos de
 *     cierre fiscal se excluyen SIEMPRE (misma fuente que el Estado de Resultados).
 *  2. Meta mensual DINÁMICA (company_targets): la define el admin vía
 *     POST /panel/api/meta; el dashboard calcula el avance con ingresos del mayor.
 *  3. Quick View (drill-down): GET /panel/api/movimientos/{id} con ticket,
 *     libro de pagos y asientos vinculados.
 *  4. Drill-down de inventario: /inventario?stock=critico filtra por stock_min.
 *  5. Seguridad: los endpoints BI requieren sesión (viven en web.php).
 */
class DashboardGerencialTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $admin;

    protected Usuario $recepcion;

    protected int $periodoId;

    protected int $monedaBaseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ContabilidadBaseSeeder::class);
        $this->seed(CuentasComplementariasSeeder::class);

        $this->admin = $this->crearUsuario('admin@t.com', 'admin', Rol::where('name', 'Administrador')->first()->id);
        $this->recepcion = $this->crearUsuario('recepcion@t.com', 'recepcion', Rol::where('name', 'Recepción / Caja')->first()->id);

        // Periodo contable ABIERTO que cubre el mes en curso (el seeder base
        // solo cubre agosto 2026). El ledger del dashboard filtra por fecha.
        $inicioMes = now()->startOfMonth();
        $this->periodoId = (int) DB::table('periodos_contables')->insertGetId([
            'nombre' => $inicioMes->translatedFormat('F Y'),
            'fecha_inicio' => $inicioMes->toDateString(),
            'fecha_fin' => $inicioMes->endOfMonth()->toDateString(),
            'estado' => 'abierto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->monedaBaseId = (int) DB::table('monedas')->where('es_base', true)->value('id');
    }

    protected function crearUsuario(string $email, string $role, int $roleId): Usuario
    {
        return Usuario::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'role_id' => $roleId,
            'is_active' => true,
        ]);
    }

    /**
     * Inserta un asiento cuadrado en el ledger con líneas [codigo, debe, haber].
     * Devuelve el id del asiento.
     */
    protected function crearAsiento(string $numero, string $fecha, array $lineas, string $modulo = 'ventas', ?int $referencia = null): int
    {
        $asientoId = (int) DB::table('asientos_contables')->insertGetId([
            'numero_asiento' => $numero,
            'fecha' => $fecha,
            'concepto' => 'Asiento de prueba '.$numero,
            'modulo_origen' => $modulo,
            'referencia_id' => $referencia,
            'tipo_asiento' => 'diario',
            'periodo_id' => $this->periodoId,
            'usuario_id' => $this->admin->id,
            'estado' => 'contabilizado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lineas as $linea) {
            DB::table('detalle_asientos')->insert([
                'asiento_id' => $asientoId,
                'cuenta_id' => (int) DB::table('cuentas_contables')->where('codigo', $linea[0])->value('id'),
                'moneda_id' => $this->monedaBaseId,
                'debe' => $linea[1],
                'haber' => $linea[2],
                'descripcion' => 'Línea de prueba',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $asientoId;
    }

    // =====================================================================
    // 1. ÚNICA FUENTE DE LA VERDAD: EL LIBRO MAYOR
    // =====================================================================

    public function test_los_kpis_del_panel_se_leen_del_libro_mayor(): void
    {
        $this->actingAs($this->admin);

        $hoy = now()->toDateString();

        // Venta de mostrador cobrada en efectivo
        $this->crearAsiento('ING-T1', $hoy, [
            ['1.1.1', 1000, 0],
            ['4.1', 0, 1000],
        ]);

        // COGS del ledger (insumos 5.2): costo real de lo vendido
        $this->crearAsiento('COG-T1', $hoy, [
            ['5.2', 300, 0],
            ['1.1.5', 0, 300],
        ]);

        // Gasto operativo (clase 6) y otros egresos (clase 7)
        $this->crearAsiento('GAS-T1', $hoy, [
            ['6.5', 200, 0],
            ['1.1.1', 0, 200],
        ], 'gastos');
        $this->crearAsiento('DIF-T1', $hoy, [
            ['7.5', 50, 0],
            ['1.1.1', 0, 50],
        ], 'mesa_cambio');

        // COMPRA DE INVENTARIO (activo 1.1.4): NUNCA debe entrar al COGS
        $this->crearAsiento('COM-T1', $hoy, [
            ['1.1.4', 5000, 0],
            ['2.1.1', 0, 5000],
        ], 'compras');

        // CIERRE FISCAL: se excluye siempre del panel
        $this->crearAsiento('CIERRE-T1', $hoy, [
            ['4.1', 99999, 0],
            ['3.1', 0, 99999],
        ], 'cierre_fiscal');

        $respuesta = $this->getJson('/panel/api/datos?rango=mes');

        $respuesta->assertStatus(200)
            ->assertJsonPath('kpis.ingresos', 1000.0)
            ->assertJsonPath('kpis.costos', 300.0)
            ->assertJsonPath('kpis.gastos_operativos', 200.0)
            ->assertJsonPath('kpis.otros_egresos', 50.0)
            ->assertJsonPath('kpis.utilidad_bruta', 700.0)
            ->assertJsonPath('kpis.margen_bruto', 70.0);

        // La compra (activo) ni el cierre fiscal distorsionan el panel
        $this->assertNotEquals(6000.0, $respuesta->json('kpis.ingresos'));

        // La vista inicial renderiza con el mismo estado
        $this->get('/')->assertStatus(200)->assertSee('Centro de Mando');
    }

    public function test_la_venta_anulada_no_cuenta_por_su_asiento_espejo(): void
    {
        $this->actingAs($this->admin);

        $hoy = now()->toDateString();

        $this->crearAsiento('ING-T1', $hoy, [
            ['1.1.1', 2000, 0],
            ['4.1', 0, 2000],
        ]);

        // Espejo de reversión (REV-*): mismas cuentas con signo opuesto
        $this->crearAsiento('REV-ING-T1', $hoy, [
            ['1.1.1', 0, 2000],
            ['4.1', 2000, 0],
        ]);

        $this->getJson('/panel/api/datos?rango=mes')
            ->assertStatus(200)
            ->assertJsonPath('kpis.ingresos', 0.0)
            ->assertJsonPath('kpis.utilidad_bruta', 0.0);
    }

    public function test_la_serie_temporal_se_agrupa_por_dia_y_por_mes(): void
    {
        $this->actingAs($this->admin);

        $this->crearAsiento('ING-T1', now()->toDateString(), [
            ['1.1.1', 1000, 0],
            ['4.1', 0, 1000],
        ]);
        $this->crearAsiento('ING-T2', now()->subDay()->toDateString(), [
            ['1.1.1', 500, 0],
            ['4.1', 0, 500],
        ]);

        // Semana: 7 días (hoy y ayer con datos, resto en cero)
        $semana = $this->getJson('/panel/api/datos?rango=semana')->json('serie');
        $this->assertCount(7, $semana['labels']);
        $this->assertEquals(1000.0, (float) end($semana['ingresos']));
        $this->assertEquals(500.0, (float) $semana['ingresos'][5]);

        // Año: 12 meses (ambos asientos caen en el mes en curso)
        $anio = $this->getJson('/panel/api/datos?rango=anio')->json('serie');
        $this->assertCount(12, $anio['labels']);
        $this->assertEquals(1500.0, (float) $anio['ingresos'][now()->month - 1]);
        $this->assertEquals(0.0, (float) $anio['ingresos'][now()->month - 2]);
    }

    public function test_los_cobros_por_metodo_se_leen_de_las_cuentas_de_fondos(): void
    {
        $this->actingAs($this->admin);

        $hoy = now()->toDateString();

        // Venta cobrada mitad efectivo, mitad tarjeta BAC (asiento ING-* real)
        $this->crearAsiento('ING-T1', $hoy, [
            ['1.1.1', 1750, 0],      // efectivo NIO
            ['1.1.2.2', 1750, 0],    // banco BAC
            ['4.1', 0, 3500],
        ]);

        $metodo = $this->getJson('/panel/api/datos?rango=mes')->json('metodo');

        $this->assertEquals(1750.0, $metodo['efectivo']);
        $this->assertEquals(1750.0, $metodo['bac']);
        $this->assertEquals(0.0, $metodo['lafise']);
    }

    // =====================================================================
    // 2. META MENSUAL DINÁMICA (company_targets)
    // =====================================================================

    public function test_la_meta_mensual_es_dinamica_y_solo_el_admin_la_edita(): void
    {
        // Fallback de instalación nueva: config('salon.meta_mensual')
        $this->actingAs($this->admin);
        $this->assertEquals(150000.0, $this->getJson('/panel/api/datos')->json('meta.monto'));
        $this->assertEquals('config', $this->getJson('/panel/api/datos')->json('meta.fuente'));

        // Recepción NO puede editar la meta
        $this->actingAs($this->recepcion);
        $this->postJson('/panel/api/meta', ['monto' => 999999])->assertStatus(403);

        // El administrador define la meta del mes en curso
        $this->actingAs($this->admin);
        $this->postJson('/panel/api/meta', ['monto' => 250000])
            ->assertStatus(200)
            ->assertJsonPath('meta.monto', 250000.0)
            ->assertJsonPath('meta.fuente', 'exacta');

        $this->assertDatabaseHas('company_targets', [
            'periodo' => now()->startOfMonth()->toDateString(),
            'monto' => 250000,
        ]);

        // El avance es REAL: ingresos clase 4 del mayor (1000 del asiento previo)
        $this->crearAsiento('ING-T1', now()->toDateString(), [
            ['1.1.1', 1000, 0],
            ['4.1', 0, 1000],
        ]);

        $meta = $this->getJson('/panel/api/datos')->json('meta');
        $this->assertEquals(250000.0, $meta['monto']);
        $this->assertEquals(1000.0, $meta['avance']);
        $this->assertEquals(0.4, $meta['progreso']);
    }

    // =====================================================================
    // 3. QUICK VIEW (DRILL-DOWN DEL MOVIMIENTO)
    // =====================================================================

    public function test_el_quick_view_devuelve_ticket_pagos_y_asientos(): void
    {
        $this->actingAs($this->recepcion);

        $sesion = SesionCaja::create([
            'user_id' => $this->recepcion->id,
            'monto_apertura' => 0,
            'estado' => 'abierta',
            'fecha_apertura' => now(),
        ]);

        $servicio = Servicio::create([
            'name' => 'Maquillaje Social',
            'price' => 3500,
            'duration' => 60,
            'is_active' => true,
        ]);

        $ventaId = $this->postJson('/sales', [
            'cart' => [[
                'id' => $servicio->id,
                'precio_c' => 3500,
                'quantity' => 2,
                'type' => 'servicio',
            ]],
            'discount' => 100,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 6900, 'tasa' => 1],
            ],
        ])->assertStatus(201)->json('sale_id');

        $respuesta = $this->getJson('/panel/api/movimientos/'.$ventaId);

        $respuesta->assertStatus(200)
            ->assertJsonPath('id', $ventaId)
            ->assertJsonPath('estado', 'pagada')
            ->assertJsonPath('total', 6900.0)
            ->assertJsonPath('descuento', 100.0);

        $this->assertEquals('Maquillaje Social', $respuesta->json('items.0.producto'));
        $this->assertEquals(2, $respuesta->json('items.0.cantidad'));
        $this->assertEquals('efectivo', $respuesta->json('pagos.0.metodo'));
        $this->assertEquals(6900.0, $respuesta->json('pagos.0.valor_nio'));

        // Trazabilidad: el asiento de ingreso de la venta está vinculado
        $asiento = $respuesta->json('asientos.0');
        $this->assertNotNull($asiento);
        $this->assertStringStartsWith('ING-', $asiento['numero']);

        // Inexistente: 404 limpio
        $this->getJson('/panel/api/movimientos/999999')->assertStatus(404);
    }

    // =====================================================================
    // 4. DRILL-DOWN DE INVENTARIO
    // =====================================================================

    public function test_el_filtro_de_inventario_critico_filtra_por_stock_minimo(): void
    {
        $this->actingAs($this->admin);

        Articulo::create([
            'producto' => 'Base Liquida Critica',
            'type' => 'producto',
            'precio_c' => 500,
            'precio_usd' => 15,
            'existencia_actual' => 1,
            'stock_min' => 5,
        ]);
        Articulo::create([
            'producto' => 'Sombras abundance',
            'type' => 'producto',
            'precio_c' => 900,
            'precio_usd' => 25,
            'existencia_actual' => 50,
            'stock_min' => 5,
        ]);

        $this->get('/inventario?stock=critico')
            ->assertStatus(200)
            ->assertSee('Base Liquida Critica')
            ->assertDontSee('Sombras abundance');

        // Sin filtro: ambos visibles
        $this->get('/inventario')
            ->assertStatus(200)
            ->assertSee('Base Liquida Critica')
            ->assertSee('Sombras abundance');
    }

    // =====================================================================
    // 5. SEGURIDAD: LOS ENDPOINTS BI REQUIEREN SESIÓN
    // =====================================================================

    public function test_los_endpoints_bi_requieren_sesion(): void
    {
        // Sin sesión: redirección al login (nunca datos financieros expuestos)
        $this->get('/panel/api/datos')->assertRedirect(route('login'));
        $this->getJson('/panel/api/datos')->assertStatus(401);
        $this->get('/panel/api/movimientos/1')->assertRedirect(route('login'));
        $this->post('/panel/api/meta', ['monto' => 1000])->assertRedirect(route('login'));
        $this->get('/')->assertRedirect(route('login'));
    }
}
