<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\ClienteGrupo;
use App\Models\ConsumoVenta;
use App\Models\DetalleVenta;
use App\Models\FormulaServicio;
use App\Models\Lote;
use App\Models\Rol;
use App\Models\Servicio;
use App\Models\ServicioVariante;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\InventarioService;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FASE 1 - MÓDULO DE ADMINISTRACIÓN (octubre 2026).
 *
 * Verifica las nuevas reglas de dominio incorporadas al motor de ventas:
 *  - Límite de crédito y facturas vencidas bloquean la facturación a crédito (CxC).
 *  - Descuento automático por grupo de fidelización cuando no hay descuento manual.
 *  - Variantes de precio: trazabilidad en sale_details y validación de pertenencia.
 *  - BOM dinámico: el consumo REAL capturado en el POS descuenta inventario con
 *    exactitud y su reversión es exacta (no usa la fórmula cambiada a posteriori).
 */
class AdminClientesServiciosTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $cajero;

    protected Servicio $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ContabilidadBaseSeeder::class);

        // Las subcuentas de caja por divisa (1.1.1.1 / 1.1.1.2) las crea una
        // migración de datos que en una base fresca corre ANTES del seeder.
        (include database_path('migrations/2026_09_03_192403_add_cuentas_faltantes_fase1.php'))->up();

        $this->cajero = Usuario::create([
            'name' => 'Cajera Prueba',
            'email' => 'cajera@test.com',
            'password' => 'password',
            'role' => 'recepcion',
            'role_id' => Rol::where('name', 'Recepción / Caja')->first()->id,
            'is_active' => true,
        ]);

        $this->servicio = Servicio::create([
            'name' => 'Alisado de Keratina',
            'price' => 3500,
            'duration' => 60,
            'is_active' => true,
        ]);

        $this->actingAs($this->cajero);
        SesionCaja::create([
            'user_id' => $this->cajero->id,
            'monto_apertura' => 0,
            'estado' => 'abierta',
            'fecha_apertura' => now(),
        ]);
    }

    private function crearCliente(array $extra = []): Cliente
    {
        return Cliente::create(array_merge(['name' => 'Cliente Prueba'], $extra));
    }

    private function carrito(array $extra = []): array
    {
        return [array_merge([
            'id' => $this->servicio->id,
            'precio_c' => 3500,
            'quantity' => 1,
            'type' => 'servicio',
        ], $extra)];
    }

    // ------------------------------------------------------------------
    // LÍMITE DE CRÉDITO Y CxC
    // ------------------------------------------------------------------

    public function test_limite_de_credito_bloquea_factura_a_credito(): void
    {
        $cliente = $this->crearCliente(['credit_limit' => 1000]);

        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'client_id' => $cliente->id,
            'action' => 'credito',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => $cliente->fresh()->motivoBloqueoCredito(3500)]);
        $this->assertSame(0, Venta::where('client_id', $cliente->id)->count(), 'No debe quedar venta parcial.');
    }

    public function test_credito_dentro_del_limite_se_emite_y_actualiza_ar(): void
    {
        $cliente = $this->crearCliente(['credit_limit' => 5000]);

        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'client_id' => $cliente->id,
            'action' => 'credito',
        ]);

        $response->assertStatus(201);

        $venta = Venta::where('client_id', $cliente->id)->firstOrFail();
        $this->assertSame(Venta::ESTADO_EMITIDA, $venta->status);
        $this->assertSame(3500.0, $cliente->fresh()->saldoPendiente());
        $this->assertSame(1500.0, $cliente->fresh()->creditoDisponible());
    }

    public function test_facturas_vencidas_bloquean_credito(): void
    {
        $cliente = $this->crearCliente(['credit_days' => 0]); // NULL = sin límite, pero con días de crédito

        $antigua = Venta::create([
            'cashier_id' => $this->cajero->id,
            'client_id' => $cliente->id,
            'subtotal' => 1000,
            'discount' => 0,
            'total' => 1000,
            'status' => Venta::ESTADO_EMITIDA,
        ]);
        DB::table('sales')->where('id', $antigua->id)->update(['created_at' => now()->subDays(10)]);

        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'client_id' => $cliente->id,
            'action' => 'credito',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('vencidas', $response->json('error'));
        $this->assertSame(1, Venta::where('client_id', $cliente->id)->count(), 'Solo la factura antigua preexistente.');
    }

    public function test_grupo_aplica_descuento_automatico_sin_descuento_manual(): void
    {
        // El seeder de la migración ya siembra VIP; si existe se actualiza el descuento
        $grupo = ClienteGrupo::firstOrCreate(['name' => 'VIP'], ['discount_percent' => 10]);
        $grupo->update(['discount_percent' => 10]);
        $cliente = $this->crearCliente(['customer_group_id' => $grupo->id]);

        // El POS moderno siempre manda 'discount': aquí se prueba el contrato
        // del API sin descuento explícito (descuento automático del grupo).
        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'client_id' => $cliente->id,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 3200, 'tasa' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $venta = Venta::where('client_id', $cliente->id)->firstOrFail();
        $this->assertSame(350.0, (float) $venta->discount, 'Descuento automático del 10% del grupo VIP.');
        $this->assertSame(3150.0, (float) $venta->total);
    }

    public function test_descuento_manual_prevalece_sobre_el_grupo(): void
    {
        $grupo = ClienteGrupo::create(['name' => 'VIP2', 'discount_percent' => 10]);
        $cliente = $this->crearCliente(['customer_group_id' => $grupo->id]);

        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0, // descuento explícito en cero: manda el cajero
            'client_id' => $cliente->id,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 3500, 'tasa' => 1],
            ],
        ])->assertStatus(201);

        $venta = Venta::where('client_id', $cliente->id)->firstOrFail();
        $this->assertSame(0.0, (float) $venta->discount);
        $this->assertSame(3500.0, (float) $venta->total);
    }

    public function test_no_se_puede_eliminar_cliente_con_facturas(): void
    {
        $cliente = $this->crearCliente();

        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'client_id' => $cliente->id,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 3500, 'tasa' => 1],
            ],
        ])->assertStatus(201);

        $this->deleteJson('/clientes/' . $cliente->id)->assertStatus(422);
        $this->assertNotNull(Cliente::find($cliente->id));
    }

    // ------------------------------------------------------------------
    // VARIANTES DE PRECIO
    // ------------------------------------------------------------------

    public function test_variante_se_registra_en_detalle_de_venta(): void
    {
        $variante = ServicioVariante::create([
            'service_id' => $this->servicio->id,
            'name' => 'Cabello Medio',
            'price' => 2000,
        ]);

        $this->postJson('/sales', [
            'cart' => $this->carrito([
                'precio_c' => 2000,
                'variant_id' => $variante->id,
            ]),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 2000, 'tasa' => 1],
            ],
        ])->assertStatus(201);

        $detalle = DetalleVenta::firstOrFail();
        $this->assertSame($variante->id, $detalle->service_variant_id);
        $this->assertSame(2000.0, (float) $detalle->unit_price);
        $this->assertSame(2000.0, (float) Venta::firstOrFail()->total);
    }

    public function test_variante_ajena_al_servicio_es_rechazada(): void
    {
        $otroServicio = Servicio::create(['name' => 'Tinte', 'price' => 1200, 'duration' => 45, 'is_active' => true]);

        $varianteAjena = ServicioVariante::create([
            'service_id' => $otroServicio->id,
            'name' => 'Raíz',
            'price' => 800,
        ]);

        $response = $this->postJson('/sales', [
            'cart' => $this->carrito([
                'precio_c' => 800,
                'variant_id' => $varianteAjena->id,
            ]),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 800, 'tasa' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Venta::count(), 'La venta completa debe revertirse si la variante no pertenece al servicio.');
    }

    // ------------------------------------------------------------------
    // BOM DINÁMICO: CONSUMO REAL vs ESTÁNDAR
    // ------------------------------------------------------------------

    private function prepararInsumo(): Articulo
    {
        $insumo = Articulo::create([
            'producto' => 'Keratina Líquida',
            'type' => 'producto_venta',
            'precio_c' => 1200,
            'existencia_actual' => 2,
            'stock_min' => 1,
            'is_fractionable' => true,
            'unit_measure' => 'ml',
            'total_volume' => 1000,
            'current_volume' => 500,
        ]);

        Lote::create([
            'item_id' => $insumo->id,
            'lote' => 'L-001',
            'fecha_entrada' => now()->subDays(5),
            'cantidad_entrada' => 2,
            'cantidad_disponible' => 2,
            'costo_unitario' => 100,
        ]);

        FormulaServicio::create([
            'service_id' => $this->servicio->id,
            'item_id' => $insumo->id,
            'quantity_used' => 60, // fórmula ESTÁNDAR: 60 ml
        ]);

        return $insumo;
    }

    public function test_consumo_real_descuenta_inventario_exacto_y_revierte_exacto(): void
    {
        $insumo = $this->prepararInsumo();

        $this->postJson('/sales', [
            'cart' => $this->carrito([
                'consumo' => [['item_id' => $insumo->id, 'cantidad' => 80]], // el estilista gastó 80 ml
            ]),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 3500, 'tasa' => 1],
            ],
        ])->assertStatus(201);

        // El inventario descuenta el consumo REAL (80 ml), no el estándar (60)
        $this->assertSame(420.0, (float) $insumo->fresh()->current_volume);

        // La fotografía del consumo queda auditada (estándar vs real)
        $consumo = ConsumoVenta::firstOrFail();
        $this->assertSame(60.0, (float) $consumo->quantity_standard);
        $this->assertSame(80.0, (float) $consumo->quantity_actual);

        // La reversión usa la MISMA fuente: devuelve exactamente 80 ml,
        // aunque la fórmula estándar siga diciendo 60.
        (new InventarioService())->revertirPorVenta(Venta::firstOrFail());
        $this->assertSame(500.0, (float) $insumo->fresh()->current_volume);
    }

    public function test_sin_consumo_real_se_aplica_formula_estandar(): void
    {
        $insumo = $this->prepararInsumo();

        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 3500, 'tasa' => 1],
            ],
        ])->assertStatus(201);

        $this->assertSame(440.0, (float) $insumo->fresh()->current_volume, 'Sin consumo editado se descuenta la fórmula estándar (60 ml).');
        $this->assertSame(0, ConsumoVenta::count());
    }

    public function test_insumo_ajeno_a_la_formula_es_rechazado(): void
    {
        $insumo = $this->prepararInsumo();
        $otro = Articulo::create([
            'producto' => 'Amoniaco',
            'type' => 'producto_venta',
            'precio_c' => 300,
            'existencia_actual' => 5,
            'stock_min' => 1,
        ]);

        $response = $this->postJson('/sales', [
            'cart' => $this->carrito([
                'consumo' => [['item_id' => $otro->id, 'cantidad' => 10]],
            ]),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 3500, 'tasa' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('fórmula', $response->json('error'));
        $this->assertSame(0, Venta::count(), 'Atomicidad: nada debe persistirse.');
        $this->assertSame(500.0, (float) $insumo->fresh()->current_volume);
    }

    public function test_costo_de_insumos_se_calcula_desde_lotes(): void
    {
        $insumo = $this->prepararInsumo(); // lote: C$100 por frasco de 1000 ml

        $servicio = Servicio::with('formulas.articulo')->find($this->servicio->id);

        // 60 ml × (100 / 1000 ml) = C$ 6.00
        $this->assertSame(6.0, $servicio->costoInsumos());
        $this->assertSame(3494.0, $servicio->margenBruto());

        // Costo por unidad de medida del insumo
        $this->assertSame(0.1, $insumo->costoPorUnidadMedida());
    }
}
