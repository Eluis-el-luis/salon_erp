<?php

namespace Tests\Feature;

use App\Models\CuentaContable;
use App\Models\DetalleAsiento;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Catálogo de cuentas: vista árbol, protección de cuentas del sistema,
 * jerarquía padre-hijo sin ciclos, eliminación segura e importador Excel
 * con validación estructural previa (nada se guarda si falla).
 */
class CatalogoCuentasTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ContabilidadBaseSeeder::class);

        // Marca las cuentas del catálogo base como protegidas (como en producción)
        DB::table('cuentas_contables')->update(['is_system_account' => true]);

        $this->admin = Usuario::create([
            'name' => 'Contador Admin',
            'email' => 'contador@test.com',
            'password' => 'password',
            'role' => 'admin',
            'role_id' => Rol::where('name', 'Administrador')->first()->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    protected function xlsx(array $filas, string $nombre = 'import_test.xlsx'): UploadedFile
    {
        $contenido = array_merge(
            [['codigo', 'nombre', 'tipo', 'naturaleza', 'codigo_padre', 'permite_movimiento', 'activa']],
            $filas
        );

        Excel::store(new class($contenido) implements FromArray
        {
            public function __construct(private array $filas) {}

            public function array(): array
            {
                return $this->filas;
            }
        }, $nombre, 'local');

        $path = Storage::disk('local')->path($nombre);

        return new UploadedFile(
            $path,
            $nombre,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_el_catalogo_se_muestra_en_arbol(): void
    {
        $response = $this->get('/catalogo');
        $response->assertStatus(200);
        $response->assertViewHas('cuentasArbol');

        // Solo las raíces llegan a la vista; los hijos se anidan
        $arbol = $response->viewData('cuentasArbol');
        $this->assertTrue($arbol->every(fn ($c) => is_null($c->cuenta_padre_id)));
    }

    public function test_crear_subcuenta_bajo_cuenta_del_sistema(): void
    {
        $caja = CuentaContable::where('codigo', '1.1.1')->first();

        $response = $this->postJson('/catalogo', [
            'codigo' => '1.1.1.9',
            'nombre' => 'Caja USD Secundaria',
            'tipo' => 'activo',
            'naturaleza' => 'deudora',
            'cuenta_padre_id' => $caja->id,
            'permite_movimiento' => true,
        ]);

        $response->assertStatus(201);

        $nueva = CuentaContable::where('codigo', '1.1.1.9')->first();
        $this->assertNotNull($nueva);
        $this->assertFalse($nueva->is_system_account); // nace libre, no protegida
        $this->assertEquals($caja->nivel + 1, $nueva->nivel);
        $this->assertEquals($caja->id, $nueva->cuenta_padre_id);
    }

    public function test_cuenta_del_sistema_solo_permite_cambiar_nombre(): void
    {
        $cuenta = CuentaContable::where('codigo', '4.1')->first();

        $response = $this->putJson('/catalogo/'.$cuenta->id, [
            'codigo' => '9.9.9', // intento de cambiar el código protegido
            'nombre' => 'Ingresos por Servicios del Salón',
            'tipo' => 'gasto',
            'naturaleza' => 'deudora',
        ]);

        $response->assertStatus(200);

        $cuenta->refresh();
        $this->assertEquals('4.1', $cuenta->codigo); // el código NO cambió
        $this->assertEquals('Ingresos por Servicios del Salón', $cuenta->nombre);
    }

    public function test_no_permite_ciclos_en_la_jerarquia(): void
    {
        $padre = CuentaContable::where('codigo', '1.1.1')->first();

        $this->postJson('/catalogo', [
            'codigo' => '1.1.1.9', 'nombre' => 'Subcuenta Test', 'tipo' => 'activo',
            'naturaleza' => 'deudora', 'cuenta_padre_id' => $padre->id, 'permite_movimiento' => true,
        ])->assertStatus(201);

        $hija = CuentaContable::where('codigo', '1.1.1.9')->first();

        // Intentar colgar la cuenta abuelo de su propia hija (ciclo)
        $response = $this->putJson('/catalogo/'.$padre->id, [
            'nombre' => $padre->nombre, // es cuenta sistema: solo nombre
        ]);
        $response->assertStatus(200); // sistema: ignora el resto

        // Caso real de ciclo con cuenta NO sistema
        DB::table('cuentas_contables')->where('id', $hija->id)->update(['is_system_account' => false]);

        $response = $this->putJson('/catalogo/'.$hija->id, [
            'codigo' => '1.1.1.9',
            'nombre' => 'Subcuenta Test',
            'tipo' => 'activo',
            'naturaleza' => 'deudora',
            'cuenta_padre_id' => $padre->id, // sin cambio: OK
            'permite_movimiento' => true,
        ]);
        $response->assertStatus(200);

        $response = $this->putJson('/catalogo/'.$hija->id, [
            'codigo' => '1.1.1.9',
            'nombre' => 'Subcuenta Test',
            'tipo' => 'activo',
            'naturaleza' => 'deudora',
            'cuenta_padre_id' => $hija->id, // su propio ID: ciclo directo
        ]);
        $response->assertStatus(422);
    }

    public function test_eliminacion_protegida(): void
    {
        // No se puede eliminar una cuenta del sistema
        $caja = CuentaContable::where('codigo', '4.1')->first();
        $this->deleteJson('/catalogo/'.$caja->id)->assertStatus(403);

        // Ni una con movimientos contables
        $periodoId = DB::table('periodos_contables')->first()->id;

        DB::table('asientos_contables')->insert([
            'numero_asiento' => 'TST-00001',
            'fecha' => now(),
            'concepto' => 'Prueba',
            'modulo_origen' => 'test',
            'periodo_id' => $periodoId,
            'usuario_id' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $asientoId = DB::table('asientos_contables')->first()->id;

        $libreConMov = CuentaContable::create([
            'codigo' => '9.1', 'nombre' => 'Libre con Movimiento', 'tipo' => 'gasto',
            'naturaleza' => 'deudora', 'nivel' => 1, 'permite_movimiento' => true, 'activa' => true,
        ]);
        DetalleAsiento::create([
            'asiento_id' => $asientoId, 'cuenta_id' => $libreConMov->id,
            'moneda_id' => DB::table('monedas')->where('es_base', true)->first()->id,
            'debe' => 10, 'haber' => 0, 'descripcion' => 'mov',
        ]);

        $this->deleteJson('/catalogo/'.$libreConMov->id)->assertStatus(403);

        // Ni una con subcuentas
        $conHija = CuentaContable::create([
            'codigo' => '9.2', 'nombre' => 'Libre con Hija', 'tipo' => 'gasto',
            'naturaleza' => 'deudora', 'nivel' => 1, 'permite_movimiento' => false, 'activa' => true,
        ]);
        CuentaContable::create([
            'codigo' => '9.2.1', 'nombre' => 'Hija', 'tipo' => 'gasto', 'naturaleza' => 'deudora',
            'cuenta_padre_id' => $conHija->id, 'nivel' => 2, 'permite_movimiento' => true, 'activa' => true,
        ]);
        $this->deleteJson('/catalogo/'.$conHija->id)->assertStatus(403);

        // Una libre, sin movimientos ni hijas: sí se elimina
        $libre = CuentaContable::create([
            'codigo' => '9.3', 'nombre' => 'Libre', 'tipo' => 'gasto',
            'naturaleza' => 'deudora', 'nivel' => 1, 'permite_movimiento' => true, 'activa' => true,
        ]);
        $this->deleteJson('/catalogo/'.$libre->id)->assertStatus(200);
        $this->assertNull(CuentaContable::find($libre->id));
    }

    public function test_importar_con_padre_inexistente_no_guarda_nada(): void
    {
        $archivo = $this->xlsx([
            ['9.5', 'Cuenta Huérfana', 'gasto', 'deudora', '88.88', '1', '1'],
        ]);

        $antes = CuentaContable::count();

        $response = $this->from('/catalogo')->post('/catalogo/importar', ['archivo' => $archivo]);

        $response->assertRedirect('/catalogo');
        $response->assertSessionHas('error');
        $this->assertEquals($antes, CuentaContable::count()); // nada se guardó
        $this->assertNull(CuentaContable::where('codigo', '9.5')->first());
    }

    public function test_importar_con_ciclo_en_el_archivo_es_rechazado(): void
    {
        $archivo = $this->xlsx([
            ['9.6', 'Ciclo A', 'gasto', 'deudora', '9.6.1', '1', '1'],
            ['9.6.1', 'Ciclo B', 'gasto', 'deudora', '9.6', '1', '1'],
        ]);

        $antes = CuentaContable::count();

        $response = $this->from('/catalogo')->post('/catalogo/importar', ['archivo' => $archivo]);

        $response->assertSessionHas('error');
        $this->assertEquals($antes, CuentaContable::count());
    }

    public function test_importar_catalogo_valido_crea_jerarquia(): void
    {
        $archivo = $this->xlsx([
            ['9.7', 'Gastos de Marketing Test', 'gasto', 'deudora', '', '0', '1'],
            ['9.7.1', 'Redes Sociales', 'gasto', 'deudora', '9.7', '1', '1'],
        ]);

        $response = $this->from('/catalogo')->post('/catalogo/importar', ['archivo' => $archivo]);

        $response->assertSessionHas('success');

        $padre = CuentaContable::where('codigo', '9.7')->first();
        $hija = CuentaContable::where('codigo', '9.7.1')->first();

        $this->assertNotNull($padre);
        $this->assertNotNull($hija);
        $this->assertEquals($padre->id, $hija->cuenta_padre_id);
        $this->assertFalse($padre->is_system_account); // las importadas nacen libres
    }

    public function test_exportar_catalogo_descarga_excel(): void
    {
        $response = $this->get('/catalogo/exportar');

        $response->assertStatus(200);
        $this->assertStringContainsString('catalogo_cuentas_', $response->headers->get('content-disposition'));
    }

    public function test_descargar_plantilla_descarga_excel(): void
    {
        $response = $this->get('/catalogo/plantilla');

        $response->assertStatus(200);
        $this->assertStringContainsString('plantilla_catalogo_cuentas', $response->headers->get('content-disposition'));
    }

    public function test_plantilla_intacta_no_crea_cuentas_al_importarla(): void
    {
        // La hoja "Plantilla" solo trae encabezados: subirla sin llenarla no
        // debe crear ninguna cuenta (validación estructural la rechaza).
        Excel::store(new \App\Exports\PlantillaCuentasExport(), 'plantilla_vacia.xlsx', 'local');

        $archivo = new UploadedFile(
            Storage::disk('local')->path('plantilla_vacia.xlsx'),
            'plantilla_vacia.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $antes = CuentaContable::count();

        $this->from('/catalogo')->post('/catalogo/importar', ['archivo' => $archivo])
            ->assertRedirect('/catalogo')
            ->assertSessionHas('error');

        $this->assertEquals($antes, CuentaContable::count());
    }
}
