<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\AsientoContable;
use App\Models\CuentaPorPagar;
use App\Models\FacturaCompra;
use App\Models\Lote;
use App\Models\Merma;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\SesionCaja;
use App\Models\StockLedger;
use App\Models\Usuario;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\CuentasComplementariasSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CADENA DE SUMINISTRO Y CUENTAS POR PAGAR CORPORATIVAS (octubre 2026).
 *
 * Verifica el ciclo de compra completo:
 *  - Factura de proveedor con IVA de compra y landed cost (descuento + envío
 *    repartidos al costo) → asiento COM-* cuadrado (DEBE inventario + 1.1.7 /
 *    HABER 2.1.1 o caja).
 *  - Costo promedio móvil y COGS del POS valorado a costo REAL de lotes
 *    (ya no al precio de venta precio_c).
 *  - Kárdex inmutable con saldo corrido valorado.
 *  - Perfil 360 del proveedor con AP en tiempo real y guardias de borrado.
 *  - Merma valorada al costo real (no al precio de venta).
 *  - Anulación de compra: bloqueos (abonos/consumos) y reversión completa.
 */
class CadenaSuministroApTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $admin;

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

        $this->actingAs($this->admin);
        SesionCaja::create([
            'user_id' => $this->admin->id,
            'monto_apertura' => 0,
            'estado' => 'abierta',
            'fecha_apertura' => now(),
        ]);
    }

    protected function crearProveedor(array $extra = []): Proveedor
    {
        return Proveedor::create(array_merge([
            'name' => 'Distribuidora de Belleza',
            'credit_days' => 15,
        ], $extra));
    }

    protected function crearArticulo(array $extra = []): Articulo
    {
        return Articulo::create(array_merge([
            'producto' => 'Keratina Test 1L',
            'type' => 'producto_interno',
            'precio_c' => 0,
            'precio_usd' => 0,
            'existencia_actual' => 0,
            'is_fractionable' => true,
            'unit_measure' => 'ml',
            'total_volume' => 1000,
            'current_volume' => 0,
        ], $extra));
    }

    protected function comprar(Proveedor $proveedor, array $articulos, array $extra = []): TestResponse
    {
        return $this->postJson('/inventario/comprar', array_merge([
            'provider_id' => $proveedor->id,
            'condicion_pago' => 'credito',
            'articulos' => $articulos,
        ], $extra));
    }

    /**
     * Líneas de un asiento por ID, indexadas por código de cuenta.
     */
    protected function detallesDeAsiento(?int $asientoId): array
    {
        $this->assertNotNull($asientoId, 'El registro no quedó vinculado a un asiento contable.');

        $lineas = [];
        foreach (AsientoContable::findOrFail($asientoId)->detalles as $detalle) {
            $codigo = $detalle->cuenta->codigo;
            if (! isset($lineas[$codigo])) {
                $lineas[$codigo] = ['debe' => 0.0, 'haber' => 0.0];
            }
            $lineas[$codigo]['debe'] += (float) $detalle->debe;
            $lineas[$codigo]['haber'] += (float) $detalle->haber;
        }

        return $lineas;
    }

    protected function assertCuadrado(array $lineas): void
    {
        $debe = (int) round(array_sum(array_column($lineas, 'debe')) * 100);
        $haber = (int) round(array_sum(array_column($lineas, 'haber')) * 100);

        $this->assertSame($debe, $haber, 'El asiento está descuadrado (Debe '.$debe / 100 .' vs Haber '.$haber / 100 .').');
    }

    protected function assertLinea(array $lineas, string $codigo, ?float $debe = null, ?float $haber = null): void
    {
        $this->assertArrayHasKey($codigo, $lineas, "El asiento no tiene línea con la cuenta {$codigo}.");
        if ($debe !== null) {
            $this->assertSame($debe, $lineas[$codigo]['debe'], "DEBE de {$codigo}.");
        }
        if ($haber !== null) {
            $this->assertSame($haber, $lineas[$codigo]['haber'], "HABER de {$codigo}.");
        }
    }

    // =====================================================================
    // CICLO DE COMPRA: FACTURA + KÁRDEX + CxP + ASIENTO
    // =====================================================================

    public function test_compra_a_credito_con_iva_actualiza_inventario_kardex_cxp_y_asiento(): void
    {
        $proveedor = $this->crearProveedor();
        $articulo = $this->crearArticulo();

        $response = $this->comprar($proveedor, [[
            'id' => $articulo->id,
            'cantidad' => 10,
            'costo' => 50,
            'impuesto_porcentaje' => 15,
            'lote' => 'K2510',
            'fecha_vencimiento' => now()->addDays(60)->toDateString(),
        ]]);

        $response->assertStatus(201);
        $factura = FacturaCompra::findOrFail($response->json('factura_id'));

        // DOCUMENTO: desglose fiscal correcto
        $this->assertSame(500.0, $factura->subtotal);
        $this->assertSame(75.0, $factura->impuestos);
        $this->assertSame(575.0, $factura->total);
        $this->assertSame('registrada', $factura->estado);

        // INVENTARIO + LOT
        $articulo->refresh();
        $this->assertSame(10.0, (float) $articulo->existencia_actual);
        $this->assertSame(50.0, (float) $articulo->costo_promedio);

        $lote = Lote::where('item_id', $articulo->id)->firstOrFail();
        $this->assertSame('K2510', $lote->lote);
        $this->assertSame(10.0, (float) $lote->cantidad_entrada);
        $this->assertSame(50.0, (float) $lote->costo_unitario);
        $this->assertSame($factura->lineas->first()->id, $lote->purchase_invoice_line_id);

        // KÁRDEX INMUTABLE: entrada valorada con saldo corrido
        $entrada = StockLedger::where('item_id', $articulo->id)->firstOrFail();
        $this->assertSame('entrada', $entrada->movimiento);
        $this->assertSame(500.0, (float) $entrada->valor);
        $this->assertSame(500.0, (float) $entrada->saldo_valor);
        $this->assertSame(10000.0, (float) $entrada->saldo_volumen);

        // CxP con días de crédito del proveedor
        $cxp = CuentaPorPagar::where('purchase_invoice_id', $factura->id)->firstOrFail();
        $this->assertSame(575.0, (float) $cxp->monto_original);
        $this->assertSame('pendiente', $cxp->estado);
        $this->assertSame(15, (int) $factura->fecha_factura->diffInDays($cxp->fecha_vencimiento));

        // ASIENTO COM-*: DEBE inventario 500 + IVA 75 / HABER CxP 575
        $lineas = $this->detallesDeAsiento($factura->asiento_contable_id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.5', debe: 500.0);
        $this->assertLinea($lineas, '1.1.7', debe: 75.0);
        $this->assertLinea($lineas, '2.1.1', haber: 575.0);
    }

    public function test_compra_de_contado_habera_caja_y_capitaliza_landed_cost(): void
    {
        $proveedor = $this->crearProveedor();
        $articulo = $this->crearArticulo(['is_fractionable' => false, 'total_volume' => 0, 'type' => 'producto_venta', 'precio_c' => 200]);

        // Subtotal 1000 - descuento 100 + envío 200 = base 1100 → costo landed 110/und
        $response = $this->comprar($proveedor, [[
            'id' => $articulo->id,
            'cantidad' => 10,
            'costo' => 100,
            'impuesto_porcentaje' => 0,
        ]], [
            'condicion_pago' => 'contado',
            'metodo_pago_contado' => 'efectivo',
            'descuento' => 100,
            'gastos_envio' => 200,
        ]);

        $response->assertStatus(201);
        $factura = FacturaCompra::findOrFail($response->json('factura_id'));

        $this->assertSame(1000.0, $factura->subtotal);
        $this->assertSame(100.0, $factura->descuento);
        $this->assertSame(200.0, $factura->gastos_envio);
        $this->assertSame(1100.0, $factura->total);
        $this->assertNull($factura->cuenta_por_pagar_id, 'El contado NO debe generar CxP.');

        // Landed cost capitalizado al lote y al kárdex
        $lote = Lote::where('item_id', $articulo->id)->firstOrFail();
        $this->assertSame(110.0, round((float) $lote->costo_unitario, 2));

        $entrada = StockLedger::where('item_id', $articulo->id)->firstOrFail();
        $this->assertSame(1100.0, (float) $entrada->saldo_valor);

        // ASIENTO: DEBE inventario 1100 / HABER caja 1100
        $lineas = $this->detallesDeAsiento($factura->asiento_contable_id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.4', debe: 1100.0);
        $this->assertLinea($lineas, '1.1.1', haber: 1100.0);
    }

    // =====================================================================
    // COSTO PROMEDIO MÓVIL Y COGS REAL EN EL POS
    // =====================================================================

    public function test_costo_promedio_movil_y_cogs_real_en_venta(): void
    {
        $proveedor = $this->crearProveedor();
        // TRAMPA: precio_c 100 (precio de venta) NO debe usarse como costo
        $articulo = $this->crearArticulo(['is_fractionable' => false, 'total_volume' => 0, 'type' => 'producto_venta', 'precio_c' => 100]);

        $this->comprar($proveedor, [['id' => $articulo->id, 'cantidad' => 2, 'costo' => 50]])->assertStatus(201);
        $this->comprar($proveedor, [['id' => $articulo->id, 'cantidad' => 3, 'costo' => 70]])->assertStatus(201);

        // Promedio móvil: (2×50 + 3×70) / 5 = 62
        $articulo->refresh();
        $this->assertSame(62.0, round((float) $articulo->costo_promedio, 2));
        $this->assertSame(62.0, round((float) $articulo->costoUnitarioActual(), 2));

        // Venta de 1 unidad cobrada de mostrador
        $this->postJson('/sales', [
            'cart' => [['id' => $articulo->id, 'precio_c' => 100, 'quantity' => 1, 'type' => 'producto_venta']],
            'discount' => 0,
            'action' => 'pagar',
        ])->assertStatus(201);

        $ventaId = DB::table('sales')->max('id');
        $this->assertNotNull($ventaId, 'No se registró la venta.');

        // CMV: costo REAL de lotes (62), no el precio de venta (100)
        $asientoCmv = AsientoContable::where('numero_asiento', 'CMV-'.str_pad((string) $ventaId, 5, '0', STR_PAD_LEFT))->first();
        $this->assertNotNull($asientoCmv, 'No se generó el asiento CMV.');

        $lineas = $this->detallesDeAsiento($asientoCmv->id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '5.1', debe: 62.0);
        $this->assertLinea($lineas, '1.1.4', haber: 62.0);

        // KÁRDEX: salida valorada al promedio del libro, saldo corrido exacto
        $salida = StockLedger::where('item_id', $articulo->id)->where('movimiento', 'salida')->firstOrFail();
        $this->assertSame(-1.0, (float) $salida->cantidad);
        $this->assertSame(-62.0, (float) $salida->valor);
        $this->assertSame(4.0, (float) $salida->saldo_cantidad);
        $this->assertSame(248.0, (float) $salida->saldo_valor); // 310 - 62
    }

    // =====================================================================
    // PERFIL 360 DEL PROVEEDOR
    // =====================================================================

    public function test_perfil_360_muestra_saldo_y_bloquea_borrado_con_cxp(): void
    {
        $proveedor = $this->crearProveedor();
        $articulo = $this->crearArticulo();

        $this->comprar($proveedor, [['id' => $articulo->id, 'cantidad' => 2, 'costo' => 50]])->assertStatus(201);

        $response = $this->get(route('proveedores.show', $proveedor->id));
        $response->assertStatus(200);
        $response->assertSee('100.00'); // saldo AP
        $response->assertSee('Distribuidora de Belleza');

        // §5.3: con historia financiera el proveedor no se borra
        $this->deleteJson('/proveedores/'.$proveedor->id)->assertStatus(422);
        $this->assertDatabaseHas('providers', ['id' => $proveedor->id]);
    }

    // =====================================================================
    // MERMA VALORADA AL COSTO REAL
    // =====================================================================

    public function test_merma_se_valora_al_costo_real_y_registra_kardex(): void
    {
        $proveedor = $this->crearProveedor();
        $articulo = $this->crearArticulo(['is_fractionable' => false, 'total_volume' => 0, 'precio_c' => 999]);

        $this->comprar($proveedor, [['id' => $articulo->id, 'cantidad' => 2, 'costo' => 50]])->assertStatus(201);

        $this->post('/mermas', [
            'item_id' => $articulo->id,
            'fecha' => now()->toDateString(),
            'tipo' => 'unidad',
            'cantidad' => 1,
            'motivo' => 'Frasco roto en almacén',
        ])->assertRedirect()->assertSessionHas('success');

        // Valor al costo real (50), NUNCA al precio de venta (999)
        $merma = Merma::where('item_id', $articulo->id)->firstOrFail();
        $this->assertSame(50.0, (float) $merma->valor);

        $articulo->refresh();
        $this->assertSame(1.0, (float) $articulo->existencia_actual);

        // Kárdex: salida con saldo corrido
        $salida = StockLedger::where('item_id', $articulo->id)->where('origen', 'merma')->firstOrFail();
        $this->assertSame(-1.0, (float) $salida->cantidad);
        $this->assertSame(-50.0, (float) $salida->valor);
        $this->assertSame(50.0, (float) $salida->saldo_valor);

        // Asiento de merma cuadrado por el costo real
        $asiento = AsientoContable::where('modulo_origen', 'mermas')->latest('id')->first();
        $this->assertNotNull($asiento);
        $lineas = $this->detallesDeAsiento($asiento->id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '6.8', debe: 50.0);
    }

    // =====================================================================
    // ANULACIÓN DE COMPRA (reversión, nunca borrado)
    // =====================================================================

    public function test_anulacion_de_compra_bloqueada_con_abonos_y_con_consumos(): void
    {
        $proveedor = $this->crearProveedor();

        // CASO 1: CxP con abonos → bloqueada
        $articuloA = $this->crearArticulo(['producto' => 'Bloqueo A']);
        $this->comprar($proveedor, [['id' => $articuloA->id, 'cantidad' => 2, 'costo' => 50]])->assertStatus(201);
        $facturaA = FacturaCompra::latest('id')->first();
        $cxpA = $facturaA->cuentaPorPagar;

        $this->post('/cuentas-por-pagar/abonar', [
            'cuenta_por_pagar_id' => $cxpA->id,
            'monto' => 20,
            'forma_pago' => 'efectivo',
        ])->assertRedirect();

        $this->post(route('compras.anular', $facturaA->id), ['motivo' => 'Factura duplicada'])
            ->assertSessionHasErrors();
        $this->assertSame('registrada', $facturaA->fresh()->estado);

        // CASO 2: lote ya consumido por una venta → bloqueada
        $articuloB = $this->crearArticulo(['producto' => 'Bloqueo B', 'is_fractionable' => false, 'total_volume' => 0, 'type' => 'producto_venta', 'precio_c' => 100]);
        $this->comprar($proveedor, [['id' => $articuloB->id, 'cantidad' => 1, 'costo' => 30]])->assertStatus(201);
        $facturaB = FacturaCompra::latest('id')->first();

        $this->postJson('/sales', [
            'cart' => [['id' => $articuloB->id, 'precio_c' => 100, 'quantity' => 1, 'type' => 'producto_venta']],
            'discount' => 0,
            'action' => 'pagar',
        ])->assertStatus(201);

        $this->post(route('compras.anular', $facturaB->id), ['motivo' => 'Compra equivocada'])
            ->assertSessionHasErrors();
        $this->assertSame('registrada', $facturaB->fresh()->estado);
        $this->assertDatabaseHas('cuentas_por_pagar', ['id' => $facturaB->cuenta_por_pagar_id, 'estado' => 'pendiente']);
    }

    public function test_anulacion_de_compra_intacta_revierte_todo_con_asiento_espejo(): void
    {
        $proveedor = $this->crearProveedor();
        $articulo = $this->crearArticulo(['producto' => 'Reversible', 'is_fractionable' => false, 'total_volume' => 0, 'type' => 'producto_venta', 'precio_c' => 100]);

        $this->comprar($proveedor, [['id' => $articulo->id, 'cantidad' => 1, 'costo' => 30]])->assertStatus(201);
        $factura = FacturaCompra::latest('id')->first();
        $cxp = $factura->cuentaPorPagar;

        $this->post(route('compras.anular', $factura->id), ['motivo' => 'Proveedor facturó de más'])
            ->assertSessionHas('success');

        // Documento y CxP revertidos (sin borrado físico)
        $this->assertSame('anulada', $factura->fresh()->estado);
        $this->assertSame('anulada', $cxp->fresh()->estado);
        $this->assertSame(0.0, (float) $cxp->fresh()->saldo_pendiente);
        $this->assertNotNull(CuentaPorPagar::find($cxp->id), 'La CxP no debe borrarse.');

        // Inventario y lote revertidos
        $articulo->refresh();
        $this->assertSame(0.0, (float) $articulo->existencia_actual);
        $this->assertSame(0.0, (float) Lote::where('item_id', $articulo->id)->latest('id')->value('cantidad_disponible'));

        // Kárdex: salida de anulación valorada al costo landed
        $salida = StockLedger::where('item_id', $articulo->id)->where('origen', 'anulacion_compra')->firstOrFail();
        $this->assertSame(-1.0, (float) $salida->cantidad);
        $this->assertSame(-30.0, (float) $salida->valor);
        $this->assertSame(0.0, (float) $salida->saldo_valor);

        // Asiento espejo REINV-COM-* cuadrado
        $asiento = AsientoContable::where('numero_asiento', 'REINV-COM-'.str_pad($factura->id, 4, '0', STR_PAD_LEFT))->firstOrFail();
        $lineas = $this->detallesDeAsiento($asiento->id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.4', haber: 30.0);
        $this->assertLinea($lineas, '2.1.1', debe: 30.0);

        // El saldo del proveedor vuelve a cero
        $this->assertSame(0.0, $proveedor->fresh()->saldoPendiente());
    }

    // =====================================================================
    // APERTURA DE KÁRDEX PARA INVENTARIO HEREDADO
    // =====================================================================

    public function test_apertura_de_kardex_para_articulo_heredado(): void
    {
        $articulo = $this->crearArticulo(['existencia_actual' => 7, 'current_volume' => 300, 'costo_promedio' => 12]);
        $articulo->update(['precio_c' => 20]);

        $this->post(route('inventario.kardex.apertura'), ['item_id' => $articulo->id])
            ->assertRedirect()->assertSessionHas('success');

        $entradas = StockLedger::where('item_id', $articulo->id)->get();
        $this->assertSame(1, $entradas->count());

        $apertura = $entradas->first();
        $this->assertSame('apertura', $apertura->origen);
        $this->assertSame(7.0, (float) $apertura->saldo_cantidad);
        $this->assertSame(7300.0, (float) $apertura->saldo_volumen); // 7×1000 + 300

        // Segunda apertura rechazada
        $this->post(route('inventario.kardex.apertura'), ['item_id' => $articulo->id])
            ->assertRedirect()->assertSessionHasErrors();
        $this->assertSame(1, StockLedger::where('item_id', $articulo->id)->count());
    }

    // =====================================================================
    // PANTALLAS
    // =====================================================================

    public function test_vistas_del_modulo_renderizan(): void
    {
        $proveedor = $this->crearProveedor();
        $articulo = $this->crearArticulo();

        $this->comprar($proveedor, [['id' => $articulo->id, 'cantidad' => 1, 'costo' => 50, 'impuesto_porcentaje' => 15]])->assertStatus(201);
        $factura = FacturaCompra::latest('id')->first();

        $this->get('/inventario/comprar')->assertStatus(200);
        $this->get('/inventario/kardex')->assertStatus(200);
        $this->get('/inventario/kardex?item_id='.$articulo->id)->assertStatus(200);
        $this->get(route('compras.show', $factura->id))->assertStatus(200);
        $this->get('/proveedores')->assertStatus(200);
    }
}
