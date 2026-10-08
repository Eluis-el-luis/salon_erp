<?php

namespace Tests\Feature;

use App\Models\Pago;
use App\Models\Rol;
use App\Models\SaldoMoneda;
use App\Models\Servicio;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Flujo de Cobro: Pagos Mixtos y Vueltos Multimoneda.
 *
 * Verifica que una venta cobrada con varios métodos/monedas:
 *  - Genere líneas en el libro de pagos (tabla pagos) con su valor en NIO.
 *  - Calcule y registre el vuelto (NIO o USD) como línea tipo 'vuelto'.
 *  - Producza un asiento de ingreso con partida doble EXACTA.
 *  - Mueva el inventario físico de divisas (ingreso por pagos, egreso por vuelto).
 *  - Rechace vueltos en USD sin saldo físico, con rollback total.
 */
class VentaPagosMixtosTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $cajero;

    protected SesionCaja $sesion;

    protected Servicio $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ContabilidadBaseSeeder::class);
        $this->seed(RoleSeeder::class);

        // Las subcuentas de caja por divisa (1.1.1.1 / 1.1.1.2) las crea una
        // migración de datos que en una base fresca corre ANTES del seeder del
        // catálogo. Se reaplica aquí (es idempotente) sobre el catálogo ya sembrado.
        (include database_path('migrations/2026_09_03_192403_add_cuentas_faltantes_fase1.php'))->up();

        $this->cajero = Usuario::create([
            'name' => 'Cajera Prueba',
            'email' => 'cajera@test.com',
            'password' => 'password',
            'role' => 'recepcion',
            // El AUTO_INCREMENT de roles no revierte entre pruebas (RefreshDatabase
            // usa transacciones): se resuelve por nombre, nunca por id fijo.
            'role_id' => Rol::where('name', 'Recepción / Caja')->first()->id,
            'is_active' => true,
        ]);

        // Las divisas se segregan por ID DE SESIÓN (no por user_id): con
        // RefreshDatabase el AUTO_INCREMENT no revierte y ambos ids divergen.
        $this->sesion = SesionCaja::create([
            'user_id' => $this->cajero->id,
            'monto_apertura' => 0,
            'estado' => 'abierta',
            'fecha_apertura' => now(),
        ]);

        $this->servicio = Servicio::create([
            'name' => 'Maquillaje Social',
            'price' => 3500,
            'duration' => 60,
            'is_active' => true,
        ]);

        $this->actingAs($this->cajero);
    }

    protected function carrito(): array
    {
        return [[
            'id' => $this->servicio->id,
            'precio_c' => 3500,
            'quantity' => 1,
            'type' => 'servicio',
        ]];
    }

    /** El caso operativo del plan: $50 USD en efectivo + el resto en tarjeta BAC. */
    public function test_venta_con_pago_mixto_usd_y_bac_cuadra_exacto(): void
    {
        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 50, 'tasa' => 36.50],
                ['metodo' => 'bac', 'moneda' => 'nio', 'monto' => 1675, 'tasa' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $venta = Venta::first();
        $this->assertNotNull($venta);
        $this->assertEquals(3500.00, (float) $venta->total);

        // Libro de pagos: 2 líneas de pago, sin vuelto
        $this->assertEquals(2, $venta->pagos()->where('tipo', 'pago')->count());
        $this->assertEquals(0, $venta->pagos()->where('tipo', 'vuelto')->count());
        $this->assertDatabaseHas('pagos', [
            'venta_id' => $venta->id,
            'metodo' => 'efectivo',
            'moneda' => 'usd',
            'monto' => 50,
            'valor_nio' => 1825.00,
        ]);

        // Los dólares físicos entraron al inventario de divisas de la caja
        $saldoUsd = SaldoMoneda::where('ubicacion_tipo', SesionCaja::class)
            ->where('ubicacion_id', $this->sesion->id)->first();
        $this->assertEquals(50.00, (float) $saldoUsd->saldo_actual);

        // El asiento de ingreso está cuadrado al centavo (debe = haber = 3500)
        $asiento = DB::table('asientos_contables')
            ->where('modulo_origen', 'ventas')->where('referencia_id', $venta->id)->first();
        $totales = DB::table('detalle_asientos')->where('asiento_id', $asiento->id)
            ->selectRaw('SUM(debe) d, SUM(haber) h')->first();

        $this->assertEquals(3500.00, (float) $totales->d);
        $this->assertEquals(3500.00, (float) $totales->h);

        // DEBE: cada pago fue a su cuenta (caja USD 1.1.1.2 y BAC 1.1.2.2)
        $cajaUsd = DB::table('cuentas_contables')->where('codigo', '1.1.1.2')->first();
        $bac = DB::table('cuentas_contables')->where('codigo', '1.1.2.2')->first();
        $this->assertDatabaseHas('detalle_asientos', [
            'asiento_id' => $asiento->id, 'cuenta_id' => $cajaUsd->id, 'debe' => 1825.00,
        ]);
        $this->assertDatabaseHas('detalle_asientos', [
            'asiento_id' => $asiento->id, 'cuenta_id' => $bac->id, 'debe' => 1675.00,
        ]);
    }

    /** Sobrepago: el vuelto en córdobas se registra y el asiento sigue cuadrado. */
    public function test_vuelto_en_cordobas_se_registra_y_cuadra(): void
    {
        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 50, 'tasa' => 36.50],
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 2000, 'tasa' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $venta = Venta::first();
        $vuelto = $venta->pagos()->where('tipo', 'vuelto')->first();

        // Recibido: 1825 + 2000 = 3825 -> vuelto C$ 325
        $this->assertNotNull($vuelto);
        $this->assertEquals('nio', $vuelto->moneda);
        $this->assertEquals(325.00, (float) $vuelto->valor_nio);

        // Debe 3825 = Haber (ingreso 3500 + vuelto 325)
        $asiento = DB::table('asientos_contables')
            ->where('modulo_origen', 'ventas')->where('referencia_id', $venta->id)->first();
        $totales = DB::table('detalle_asientos')->where('asiento_id', $asiento->id)
            ->selectRaw('SUM(debe) d, SUM(haber) h')->first();

        $this->assertEquals(3825.00, (float) $totales->d);
        $this->assertEquals(3825.00, (float) $totales->h);

        // El vuelto acredita la caja de córdobas (1.1.1)
        $cajaNio = DB::table('cuentas_contables')->where('codigo', '1.1.1')->first();
        $this->assertDatabaseHas('detalle_asientos', [
            'asiento_id' => $asiento->id, 'cuenta_id' => $cajaNio->id, 'haber' => 325.00,
        ]);
    }

    /** Vuelto en USD: sale del inventario físico de dólares y acredita 1.1.1.2. */
    public function test_vuelto_en_dolares_egresa_divisas_y_cuadra(): void
    {
        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 100, 'tasa' => 36.50],
            ],
            'change_currency' => 'usd',
        ]);

        $response->assertStatus(201);

        $venta = Venta::first();
        $vuelto = $venta->pagos()->where('tipo', 'vuelto')->first();

        // Recibido: $100 x 36.50 = 3650 -> vuelto C$ 150 entregado en USD
        $this->assertNotNull($vuelto);
        $this->assertEquals('usd', $vuelto->moneda);
        $this->assertEquals(round(150 / 36.50, 2), (float) $vuelto->monto);
        $this->assertEquals(150.00, (float) $vuelto->valor_nio);

        // El inventario físico de USD quedó en 100 - vuelto
        $saldoUsd = SaldoMoneda::where('ubicacion_tipo', SesionCaja::class)
            ->where('ubicacion_id', $this->sesion->id)->first();
        $this->assertEqualsWithDelta(
            100 - round(150 / 36.50, 2),
            (float) $saldoUsd->saldo_actual,
            0.000001
        );

        // Asiento cuadrado al centavo y vuelto acreditado a Caja USD (1.1.1.2)
        $asiento = DB::table('asientos_contables')
            ->where('modulo_origen', 'ventas')->where('referencia_id', $venta->id)->first();
        $totales = DB::table('detalle_asientos')->where('asiento_id', $asiento->id)
            ->selectRaw('SUM(debe) d, SUM(haber) h')->first();

        $this->assertEquals((float) $totales->d, (float) $totales->h);

        $cajaUsd = DB::table('cuentas_contables')->where('codigo', '1.1.1.2')->first();
        $this->assertDatabaseHas('detalle_asientos', [
            'asiento_id' => $asiento->id, 'cuenta_id' => $cajaUsd->id, 'haber' => 150.00,
        ]);
    }

    /** Sin dólares físicos en caja, el vuelto en USD se rechaza con rollback total. */
    public function test_vuelto_usd_sin_saldo_fisico_se_rechaza_con_rollback(): void
    {
        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 5000, 'tasa' => 1],
            ],
            'change_currency' => 'usd',
            'exchange_rate' => 36.50,
        ]);

        $response->assertStatus(422);

        // Rollback total: ni venta, ni pagos, ni asientos
        $this->assertEquals(0, Venta::count());
        $this->assertEquals(0, Pago::count());
        $this->assertEquals(0, DB::table('asientos_contables')->count());
    }

    /** El vuelto en NIO no entra al resumen de efectivo de la caja del turno. */
    public function test_efectivo_nio_neto_resta_el_vuelto_en_el_arqueo(): void
    {
        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 2000, 'tasa' => 1],
                ['metodo' => 'bac', 'moneda' => 'nio', 'monto' => 2000, 'tasa' => 1],
            ],
        ])->assertStatus(201); // recibido 4000 -> vuelto 500

        $efectivo = Venta::efectivoNioNeto(
            Venta::where('cashier_id', $this->cajero->id)->where('created_at', '>=', now()->subMinute())
        );

        $this->assertEquals(1500.00, $efectivo); // 2000 cobrados - 500 de vuelto

        $totales = Venta::totalesPorMetodo(
            Venta::where('cashier_id', $this->cajero->id)->where('created_at', '>=', now()->subMinute())
        );

        $this->assertEquals(1500.00, $totales['efectivo']);
        $this->assertEquals(2000.00, $totales['bac']);
        $this->assertEquals(0.00, $totales['lafise']);
    }
}
