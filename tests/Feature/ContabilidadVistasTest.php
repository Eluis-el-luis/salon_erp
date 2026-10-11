<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vistas contables (Libro Mayor, Asientos Manuales) y rendering del stack de
 * scripts: el layout debe incluir @stack('scripts') para que las vistas con
 * @push('scripts') (catálogo, crear asiento) registren sus componentes Alpine.
 */
class ContabilidadVistasTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ContabilidadBaseSeeder::class);

        $this->admin = Usuario::create([
            'name' => 'Contador Admin',
            'email' => 'contador@test.com',
            'password' => 'password',
            'role' => 'admin',
            'role_id' => Rol::where('name', 'Administrador')->first()->id ?? null,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    /**
     * Crea un asiento con un detalle en la cuenta indicada.
     */
    protected function crearAsiento(string $numero, string $fecha, int $cuentaId, float $debe): int
    {
        $periodoId = DB::table('periodos_contables')->first()->id;
        $monedaId = DB::table('monedas')->where('es_base', true)->first()->id;

        $asientoId = DB::table('asientos_contables')->insertGetId([
            'numero_asiento' => $numero,
            'fecha' => $fecha,
            'concepto' => 'Movimiento ' . $numero,
            'modulo_origen' => 'manual',
            'periodo_id' => $periodoId,
            'usuario_id' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('detalle_asientos')->insert([
            'asiento_id' => $asientoId,
            'cuenta_id' => $cuentaId,
            'moneda_id' => $monedaId,
            'debe' => $debe,
            'haber' => 0,
            'descripcion' => 'Cargo ' . $numero,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $asientoId;
    }

    public function test_el_componente_alpine_del_catalogo_se_renderiza(): void
    {
        // Antes del @stack('scripts') en el layout, el @push de la vista nunca
        // se imprimía: el árbol desaparecía y los botones quedaban muertos.
        $response = $this->get('/catalogo');

        $response->assertStatus(200);
        $response->assertSee('function catalogoCuentas', false);
        $response->assertSee('mostrarProtegidas', false);
    }

    public function test_libro_mayor_filtra_por_rango_de_fechas(): void
    {
        $cuentaId = DB::table('cuentas_contables')->where('codigo', '5.1')->value('id');

        $this->crearAsiento('TST-ENE', '2026-01-15', $cuentaId, 100);
        $this->crearAsiento('TST-MAR', '2026-03-20', $cuentaId, 50);

        $response = $this->get('/contabilidad/mayor?' . http_build_query([
            'cuenta_id' => $cuentaId,
            'fecha_desde' => '2026-01-01',
            'fecha_hasta' => '2026-01-31',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Movimiento TST-ENE');
        $response->assertSee('C$ 100.00', false);
        $response->assertDontSee('Movimiento TST-MAR');
        $response->assertDontSee('C$ 50.00', false);
    }

    public function test_libro_mayor_sin_filtros_muestra_todos_los_movimientos(): void
    {
        $cuentaId = DB::table('cuentas_contables')->where('codigo', '5.1')->value('id');

        $this->crearAsiento('TST-ENE', '2026-01-15', $cuentaId, 100);
        $this->crearAsiento('TST-MAR', '2026-03-20', $cuentaId, 50);

        $response = $this->get('/contabilidad/mayor?' . http_build_query(['cuenta_id' => $cuentaId]));

        $response->assertStatus(200);
        $response->assertSee('Movimiento TST-ENE');
        $response->assertSee('Movimiento TST-MAR');
    }

    public function test_index_de_asientos_manuales_renderiza(): void
    {
        $cuentaId = DB::table('cuentas_contables')->where('codigo', '5.1')->value('id');
        $this->crearAsiento('MAN-TEST1', now()->toDateString(), $cuentaId, 80);

        $response = $this->get('/asientos');

        $response->assertStatus(200);
        $response->assertSee('MAN-TEST1');
    }
}
