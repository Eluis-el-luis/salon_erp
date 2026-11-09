<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\CuentasComplementariasSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Permisos financieros y Estado de Resultados (RBAC reestructurado, octubre 2026):
 *  - 'alvaro' (Propietario): bypass global a TODO, incluida contabilidad.
 *  - 'contador' (Auditoría financiera): acceso completo al bloque financiero
 *    (contabilidad, catálogo, asientos, periodos, bancos, CxP, retiros, reportes).
 *  - 'admin' (Administrador operativo): gestión operativa (inventario, RRHH, caja,
 *    proveedores, servicios, respaldos). NO accede a contabilidad (diario, mayor,
 *    resultados, balance, catálogo, asientos, periodos, cierre fiscal).
 *  - 'recepcion': POS, ventas, arqueo, mesa de cambio, adelantos, caja chica.
 *  - El Estado de Resultados filtra por mes (default: mes actual) y admite histórico.
 */
class AccesoFinanzasContadorTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $alvaro;      // Propietario (bypass global)
    protected Usuario $admin;       // Administrador operativo (SIN contabilidad)
    protected Usuario $contador;    // Contador / Auditoría financiera
    protected Usuario $recepcion;   // Recepción / Caja

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ContabilidadBaseSeeder::class);
        $this->seed(CuentasComplementariasSeeder::class);

        $rolAlvaro = Rol::where('name', 'Álvaro (Propietario)')->first()->id;
        $rolAdmin = Rol::where('name', 'Administrador')->first()->id;
        $rolContador = Rol::where('name', 'Contador')->first()->id;
        $rolRecepcion = Rol::where('name', 'Recepción / Caja')->first()->id;

        $this->alvaro = $this->crearUsuario('alvaro@t.com', 'alvaro', $rolAlvaro);
        $this->admin = $this->crearUsuario('admin@t.com', 'admin', $rolAdmin);
        $this->contador = $this->crearUsuario('contador@t.com', 'contador', $rolContador);
        $this->recepcion = $this->crearUsuario('recepcion@t.com', 'recepcion', $rolRecepcion);
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

    // =====================================================================
    // PERMISOS: RBAC REESTRUCTURADO
    // =====================================================================

    public function test_alvaro_tiene_bypass_global(): void
    {
        $this->actingAs($this->alvaro);

        // Álvaro accede a TODO: contabilidad, operación, RRHH, inventario
        foreach ([
            '/catalogo',
            '/contabilidad',
            '/contabilidad/resultados',
            '/bancos',
            '/empleados',
            '/nomina',
            '/inventario',
            '/proveedores',
            '/backups',
            '/servicios',
        ] as $ruta) {
            $this->get($ruta)->assertStatus(200, "Álvaro no pudo entrar a {$ruta}");
        }
    }

    public function test_el_contador_accede_a_todas_las_vistas_financieras(): void
    {
        $this->actingAs($this->contador);

        foreach ([
            '/catalogo',
            '/contabilidad',
            '/contabilidad/mayor',
            '/contabilidad/resultados',
            '/contabilidad/balance',
            '/asientos',
            '/periodos',
            '/bancos',
            '/cuentas-por-pagar',
            '/retiros',
            '/reportes',
        ] as $ruta) {
            $this->get($ruta)->assertStatus(200, "El contador no pudo entrar a {$ruta}");
        }
    }

    public function test_el_contador_puede_registrar_gastos_y_abonos_de_cxp(): void
    {
        $this->actingAs($this->contador);

        // El registro de gasto es una operación contable: le compete al contador.
        $this->post('/contabilidad/gasto', [
            'descripcion' => 'Compra de insumos de limpieza',
            'monto' => 150,
            'cuenta_id' => DB::table('cuentas_contables')->where('codigo', '6.5')->value('id'),
            'metodo_pago' => 'efectivo',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, DB::table('asientos_contables')->where('modulo_origen', 'gastos')->count());
    }

    public function test_el_contador_no_accede_a_operaciones_no_financieras(): void
    {
        $this->actingAs($this->contador);

        foreach (['/empleados', '/nomina', '/inventario', '/proveedores', '/backups', '/servicios'] as $ruta) {
            $this->get($ruta)->assertStatus(403, "El contador NO debió entrar a {$ruta}");
        }
    }

    public function test_el_admin_no_accede_a_contabilidad(): void
    {
        $this->actingAs($this->admin);

        // El administrador operativo NO tiene acceso a contabilidad
        foreach (['/catalogo', '/contabilidad', '/contabilidad/resultados', '/asientos', '/periodos'] as $ruta) {
            $this->get($ruta)->assertStatus(403, "El admin NO debió entrar a {$ruta}");
        }
    }

    public function test_el_admin_accede_a_operaciones_y_bancos(): void
    {
        $this->actingAs($this->admin);

        // El administrador SÍ accede a operaciones, bancos, retiros, reportes
        foreach (['/empleados', '/nomina', '/inventario', '/proveedores', '/backups', '/servicios', '/bancos', '/retiros', '/reportes', '/cuentas-por-pagar'] as $ruta) {
            $this->get($ruta)->assertStatus(200, "El admin debió entrar a {$ruta}");
        }
    }

    public function test_recepcion_no_accede_al_bloque_contable(): void
    {
        $this->actingAs($this->recepcion);

        foreach (['/catalogo', '/contabilidad', '/contabilidad/resultados', '/bancos', '/asientos', '/periodos', '/reportes'] as $ruta) {
            $this->get($ruta)->assertStatus(403, "Recepción NO debió entrar a {$ruta}");
        }
    }

    public function test_recepcion_mantiene_sus_rutas_de_caja(): void
    {
        $this->actingAs($this->recepcion);

        $this->get('/mesa-cambio')->assertStatus(200);
        $this->get('/caja/arqueo')->assertStatus(200);
        $this->get('/caja-chica')->assertStatus(200);
    }

    // =====================================================================
    // ESTADO DE RESULTADOS CON FILTRO DE MES (usando contador o alvaro)
    // =====================================================================

    /**
     * Inserta un asiento de resultado (ingreso al haber o gasto al debe).
     */
    protected function asientoResultado(string $numero, string $fecha, string $codigoCuenta, float $monto, bool $alHaber): void
    {
        $periodoId = DB::table('periodos_contables')->first()->id;
        $monedaId = DB::table('monedas')->where('es_base', true)->first()->id;
        $cuentaId = DB::table('cuentas_contables')->where('codigo', $codigoCuenta)->value('id');

        $asientoId = DB::table('asientos_contables')->insertGetId([
            'numero_asiento' => $numero,
            'fecha' => $fecha,
            'concepto' => 'Resultado '.$numero,
            'modulo_origen' => 'manual',
            'periodo_id' => $periodoId,
            'usuario_id' => $this->alvaro->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('detalle_asientos')->insert([
            'asiento_id' => $asientoId,
            'cuenta_id' => $cuentaId,
            'moneda_id' => $monedaId,
            'debe' => $alHaber ? 0 : $monto,
            'haber' => $alHaber ? $monto : 0,
            'descripcion' => 'Línea '.$numero,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_por_defecto_muestra_solo_el_mes_actual(): void
    {
        $hoy = now();

        $this->asientoResultado('RES-ACT1', $hoy->toDateString(), '4.1', 500, alHaber: true);
        $this->asientoResultado('RES-ACT2', $hoy->toDateString(), '6.1', 200, alHaber: false);

        $mesPasado = $hoy->copy()->subMonth();
        $this->asientoResultado('RES-PAS1', $mesPasado->format('Y-m-15'), '4.1', 900, alHaber: true);

        $respuesta = $this->actingAs($this->contador)->get('/contabilidad/resultados');

        $respuesta->assertStatus(200);
        $respuesta->assertSee('C$ 500.00', false);   // ingreso del mes actual
        $respuesta->assertSee('C$ 200.00', false);   // gasto del mes actual
        $respuesta->assertDontSee('C$ 900.00', false); // ingreso del mes pasado
    }

    public function test_el_filtro_mes_muestra_sus_propios_movimientos(): void
    {
        $hoy = now();
        $mesPasado = $hoy->copy()->subMonth();

        $this->asientoResultado('RES-ACT1', $hoy->toDateString(), '4.1', 500, alHaber: true);
        $this->asientoResultado('RES-PAS1', $mesPasado->format('Y-m-15'), '4.1', 900, alHaber: true);
        $this->asientoResultado('RES-PAS2', $mesPasado->format('Y-m-20'), '6.1', 300, alHaber: false);

        $respuesta = $this->actingAs($this->contador)
            ->get('/contabilidad/resultados?mes='.$mesPasado->format('Y-m'));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('C$ 900.00', false);   // ingreso del mes seleccionado
        $respuesta->assertSee('C$ 300.00', false);   // gasto del mes seleccionado
        $respuesta->assertDontSee('C$ 500.00', false); // ingreso del mes actual
    }

    public function test_historico_completo_muestra_todos_los_meses(): void
    {
        $hoy = now();
        $mesPasado = $hoy->copy()->subMonth();

        $this->asientoResultado('RES-ACT1', $hoy->toDateString(), '4.1', 500, alHaber: true);
        $this->asientoResultado('RES-PAS1', $mesPasado->format('Y-m-15'), '4.1', 900, alHaber: true);

        $respuesta = $this->actingAs($this->contador)->get('/contabilidad/resultados?mes=todo');

        $respuesta->assertStatus(200);
        $respuesta->assertSee('C$ 1,400.00', false); // 500 + 900 acumulados
    }

    public function test_mes_invalido_recae_en_el_mes_actual(): void
    {
        $this->asientoResultado('RES-ACT1', now()->toDateString(), '4.1', 500, alHaber: true);
        $this->asientoResultado('RES-PAS1', now()->copy()->subMonth()->format('Y-m-15'), '4.1', 900, alHaber: true);

        $respuesta = $this->actingAs($this->contador)->get('/contabilidad/resultados?mes=2026-13');

        $respuesta->assertStatus(200);
        $respuesta->assertSee('C$ 500.00', false);
        $respuesta->assertDontSee('C$ 900.00', false);
    }

    public function test_el_contador_usa_el_filtro_de_resultados(): void
    {
        $this->actingAs($this->contador);

        $this->asientoResultado('RES-ACT1', now()->toDateString(), '4.1', 500, alHaber: true);

        $this->get('/contabilidad/resultados?mes=todo')
            ->assertStatus(200)
            ->assertSee('Histórico completo');
    }

    public function test_alvaro_tambien_usa_el_filtro_de_resultados(): void
    {
        $this->actingAs($this->alvaro);

        $this->asientoResultado('RES-ACT1', now()->toDateString(), '4.1', 500, alHaber: true);

        $this->get('/contabilidad/resultados?mes=todo')
            ->assertStatus(200)
            ->assertSee('Histórico completo');
    }
}
