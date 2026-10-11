<?php

namespace Tests\Feature;

use App\Models\AsientoContable;
use App\Models\Banco;
use App\Models\CuentaBancaria;
use App\Models\CuentaContable;
use App\Models\DiferencialCambiario;
use App\Models\MovimientoBancario;
use App\Models\OperacionCambio;
use App\Models\Rol;
use App\Models\SaldoMoneda;
use App\Models\SesionCaja;
use App\Models\Transferencia;
use App\Models\Usuario;
use App\Models\ConciliacionBancaria;
use Database\Seeders\ContabilidadBaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Auditoría financiera de Bancos y Mesa de Cambio (fase corporativa):
 *  - Cuadre exacto de partida doble en compras/ventas de divisas.
 *  - Diferencial cambiario a valor libro (ganancia 4.6 / pérdida 7.5).
 *  - Rollback completo cuando no hay saldo físico de divisas.
 *  - Cargos bancarios al gasto 6.9 y transferencias con comisión.
 *  - Conciliación bancaria: sólo cierra con diferencia C$ 0.00.
 */
class BancosMesaCambioTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $admin;

    protected int $idNio;

    protected int $idUsd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ContabilidadBaseSeeder::class);
        // Subcuentas del motor (1.1.1.2, 4.6, 6.9, 7.5, 1.1.2.3/4): en producción
        // las deja la migración corporativa; aquí el catálogo se siembra después.
        $this->seed(\Database\Seeders\CuentasComplementariasSeeder::class);

        $this->idNio = (int) DB::table('monedas')->where('codigo', 'NIO')->value('id');
        $this->idUsd = (int) DB::table('monedas')->where('codigo', 'USD')->value('id');

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

    // =====================================================================
    // HELPERS
    // =====================================================================

    protected function crearCaja(float $apertura = 5000): SesionCaja
    {
        return SesionCaja::create([
            'user_id' => $this->admin->id,
            'monto_apertura' => $apertura,
            'estado' => 'abierta',
            'fecha_apertura' => now(),
        ]);
    }

    protected function crearCuentaBancaria(float $saldo = 0, bool $usd = false, string $bancoCodigo = 'BAC'): CuentaBancaria
    {
        $banco = Banco::firstOrCreate(
            ['codigo' => $bancoCodigo],
            ['nombre' => $bancoCodigo === 'BAC' ? 'BAC Credomatic' : 'Banco LAFISE Bancentro', 'activo' => true]
        );

        $codigoContable = $usd
            ? ($bancoCodigo === 'BAC' ? '1.1.2.3' : '1.1.2.4')
            : ($bancoCodigo === 'BAC' ? '1.1.2.2' : '1.1.2.1');

        return CuentaBancaria::create([
            'banco_id' => $banco->id,
            'numero_cuenta' => $bancoCodigo . '-TEST-' . uniqid(),
            'tipo_cuenta' => 'Corriente',
            'moneda_id' => $usd ? $this->idUsd : $this->idNio,
            'cuenta_contable_id' => CuentaContable::where('codigo', $codigoContable)->value('id'),
            'saldo_actual' => $saldo,
            'activa' => true,
        ]);
    }

    /**
     * Líneas de un asiento por ID, indexadas por código de cuenta:
     * [codigo => ['debe','haber']].
     */
    protected function detallesDeAsiento(?int $asientoId): array
    {
        $this->assertNotNull($asientoId, 'El registro no quedó vinculado a un asiento contable.');

        $lineas = [];
        foreach (AsientoContable::findOrFail($asientoId)->detalles as $detalle) {
            $codigo = $detalle->cuenta->codigo;
            // Una cuenta puede repetirse (ej. comisión): se suman sus líneas.
            if (!isset($lineas[$codigo])) {
                $lineas[$codigo] = ['debe' => 0.0, 'haber' => 0.0];
            }
            $lineas[$codigo]['debe'] += (float) $detalle->debe;
            $lineas[$codigo]['haber'] += (float) $detalle->haber;
        }

        return $lineas;
    }

    /**
     * Asiento de la última operación de cambio registrada.
     */
    protected function asientoDeUltimaOperacion(): array
    {
        $operacion = OperacionCambio::orderByDesc('id')->first();
        $this->assertNotNull($operacion, 'No se registró ninguna operación de cambio.');

        return $this->detallesDeAsiento($operacion->asiento_contable_id);
    }

    protected function assertCuadrado(array $lineas): void
    {
        $debe = (int) round(array_sum(array_column($lineas, 'debe')) * 100);
        $haber = (int) round(array_sum(array_column($lineas, 'haber')) * 100);

        $this->assertSame($debe, $haber, 'El asiento está descuadrado (Debe ' . $debe / 100 . ' vs Haber ' . $haber / 100 . ').');
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

    protected function saldoUsdDeCaja(int $cajaId): ?SaldoMoneda
    {
        return SaldoMoneda::where('moneda_id', $this->idUsd)
            ->where('ubicacion_tipo', 'App\Models\SesionCaja')
            ->where('ubicacion_id', $cajaId)
            ->first();
    }

    // =====================================================================
    // PANTALLAS (smoke de vistas Blade + Alpine)
    // =====================================================================

    public function test_pantallas_bancarias_renderizan(): void
    {
        $cuenta = $this->crearCuentaBancaria(100);

        $this->get('/bancos')->assertOk()->assertSee('Gestión Bancaria');
        $this->get('/bancos/' . $cuenta->id . '/libro')->assertOk();
        $this->get('/bancos/' . $cuenta->id . '/conciliacion')->assertOk();
    }

    public function test_pantalla_mesa_cambio_renderiza(): void
    {
        $this->get('/mesa-cambio')->assertOk()->assertSee('Mesa de Cambio');
    }

    // =====================================================================
    // MESA DE CAMBIO
    // =====================================================================

    public function test_compra_de_dolares_actualiza_boveda_y_cuadra_asiento(): void
    {
        $caja = $this->crearCaja(5000);

        $respuesta = $this->post('/mesa-cambio/operar', [
            'tipo' => 'compra',
            'monto_usd' => 100,
            'tasa_aplicada' => 36.8,
            'tasa_oficial' => 36.6,
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');

        // Bóveda USD de la caja con costo promedio a la tasa de compra (6 decimales).
        $saldo = $this->saldoUsdDeCaja($caja->id);
        $this->assertNotNull($saldo);
        $this->assertSame(100.0, (float) $saldo->saldo_actual);
        $this->assertSame(36.8, (float) $saldo->costo_promedio_ponderado);

        // Asiento cuadrado: entra USD a la caja de dólares, sale NIO de la caja general.
        $lineas = $this->asientoDeUltimaOperacion();
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.1.2', debe: 3680.0);
        $this->assertLinea($lineas, '1.1.1', haber: 3680.0);

        // Operación con trazabilidad de bóvedas y spread vs. tasa oficial.
        $operacion = OperacionCambio::first();
        $this->assertSame('compra', $operacion->tipo);
        $this->assertSame(20.0, (float) $operacion->diferencial_oficial); // 100 × (36.8 − 36.6)
        $this->assertNotNull($operacion->asiento_contable_id);
    }

    public function test_venta_de_dolares_con_ganancia_valora_a_libro_y_cuadra(): void
    {
        $caja = $this->crearCaja(5000);

        $this->post('/mesa-cambio/operar', [
            'tipo' => 'compra',
            'monto_usd' => 100,
            'tasa_aplicada' => 36.8,
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ]);

        $respuesta = $this->post('/mesa-cambio/operar', [
            'tipo' => 'venta',
            'monto_usd' => 100,
            'tasa_aplicada' => 37.2, // tasa de calle por encima del costo 36.8
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');

        // C$ 3720 recibidos − C$ 3680 de valor libro = C$ 40 de ganancia.
        $lineas = $this->asientoDeUltimaOperacion();
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.1', debe: 3720.0);
        $this->assertLinea($lineas, '1.1.1.2', haber: 3680.0);
        $this->assertLinea($lineas, '4.6', haber: 40.0);

        $operacion = OperacionCambio::orderBy('id')->skip(1)->first();
        $this->assertSame(40.0, (float) $operacion->diferencial_costo);
        $this->assertSame(36.8, (float) $operacion->tasa_costo_usd);

        $diferencial = DiferencialCambiario::first();
        $this->assertNotNull($diferencial);
        $this->assertSame('ganancia', $diferencial->tipo);
        $this->assertSame(40.0, (float) $diferencial->diferencia_calculada);

        // La bóveda USD queda vacía.
        $this->assertSame(0.0, (float) $this->saldoUsdDeCaja($caja->id)->saldo_actual);
    }

    public function test_venta_con_perdida_contabiliza_la_cuenta_7_5_y_cuadra(): void
    {
        $caja = $this->crearCaja(5000);

        $this->post('/mesa-cambio/operar', [
            'tipo' => 'compra',
            'monto_usd' => 100,
            'tasa_aplicada' => 36.8,
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ]);

        // Se vende por debajo del costo: C$ 3650 recibidos − C$ 3680 de libro = −30.
        $this->post('/mesa-cambio/operar', [
            'tipo' => 'venta',
            'monto_usd' => 100,
            'tasa_aplicada' => 36.5,
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ])->assertSessionHas('success');

        $lineas = $this->asientoDeUltimaOperacion();
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.1', debe: 3650.0);
        $this->assertLinea($lineas, '1.1.1.2', haber: 3680.0);
        $this->assertLinea($lineas, '7.5', debe: 30.0);

        $this->assertSame('perdida', DiferencialCambiario::first()->tipo);
    }

    public function test_venta_sin_saldo_usd_hace_rollback_completo(): void
    {
        $caja = $this->crearCaja(5000);

        $this->post('/mesa-cambio/operar', [
            'tipo' => 'compra',
            'monto_usd' => 100,
            'tasa_aplicada' => 36.8,
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ]);

        $respuesta = $this->from('/mesa-cambio')->post('/mesa-cambio/operar', [
            'tipo' => 'venta',
            'monto_usd' => 150, // hay sólo 100
            'tasa_aplicada' => 37.0,
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ]);

        $respuesta->assertRedirect('/mesa-cambio');
        $respuesta->assertSessionHasErrors('error');

        // No hay operación nueva, ni asiento nuevo, y el saldo queda intacto.
        $this->assertSame(1, OperacionCambio::count());
        $this->assertSame(1, AsientoContable::where('modulo_origen', 'mesa_cambio')->count());
        $this->assertSame(100.0, (float) $this->saldoUsdDeCaja($caja->id)->saldo_actual);
    }

    public function test_compra_desde_caja_sin_efectivo_suficiente_es_rechazada(): void
    {
        $caja = $this->crearCaja(1000); // apertura menor que el NIO a entregar

        $this->from('/mesa-cambio')->post('/mesa-cambio/operar', [
            'tipo' => 'compra',
            'monto_usd' => 100,
            'tasa_aplicada' => 36.8, // necesita C$ 3680
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ])->assertSessionHasErrors('error');

        $this->assertSame(0, OperacionCambio::count());
        $this->assertNull($this->saldoUsdDeCaja($caja->id));
    }

    // =====================================================================
    // BANCOS
    // =====================================================================

    public function test_deposito_nio_desde_caja_genera_movimiento_y_asiento(): void
    {
        $caja = $this->crearCaja(500);
        $cuenta = $this->crearCuentaBancaria(0);

        $respuesta = $this->post('/bancos/depositar', [
            'cuenta_destino_id' => $cuenta->id,
            'monto' => 300,
            'caja_origen_id' => $caja->id,
            'moneda' => 'nio',
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');

        $cuenta->refresh();
        $this->assertSame(300.0, (float) $cuenta->saldo_actual);

        $movimiento = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->first();
        $this->assertNotNull($movimiento);
        $this->assertSame('deposito', $movimiento->tipo);
        $this->assertSame('pendiente', $movimiento->estado_conciliacion);
        $this->assertNotNull($movimiento->asiento_contable_id);

        $lineas = $this->detallesDeAsiento($movimiento->asiento_contable_id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.2.2', debe: 300.0);
        $this->assertLinea($lineas, '1.1.1', haber: 300.0);

        $this->assertSame(1, Transferencia::count());
    }

    public function test_deposito_usd_egresa_divisas_de_caja_a_valor_libro(): void
    {
        $caja = $this->crearCaja(5000);
        $cuenta = $this->crearCuentaBancaria(0, usd: true);

        // Primero entren dólares a la caja por la mesa de cambio.
        $this->post('/mesa-cambio/operar', [
            'tipo' => 'compra',
            'monto_usd' => 100,
            'tasa_aplicada' => 36.8,
            'origen_tipo' => 'sesion',
            'origen_id' => $caja->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
        ]);

        $this->post('/bancos/depositar', [
            'cuenta_destino_id' => $cuenta->id,
            'monto' => 100,
            'caja_origen_id' => $caja->id,
            'moneda' => 'usd',
        ])->assertSessionHas('success');

        $cuenta->refresh();
        $this->assertSame(100.0, (float) $cuenta->saldo_actual);

        // El saldo físico de la caja queda en cero.
        $this->assertSame(0.0, (float) $this->saldoUsdDeCaja($caja->id)->saldo_actual);

        // El asiento valora a costo promedio (100 × 36.8) sin diferencial.
        $movimiento = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->where('tipo', 'deposito')->first();
        $lineas = $this->detallesDeAsiento($movimiento?->asiento_contable_id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.2.3', debe: 3680.0);
        $this->assertLinea($lineas, '1.1.1.2', haber: 3680.0);
    }

    public function test_transferencia_banco_a_banco_con_comision_cuadra(): void
    {
        $origen = $this->crearCuentaBancaria(1000, bancoCodigo: 'BAC');
        $destino = $this->crearCuentaBancaria(0, bancoCodigo: 'LAFISE');

        $respuesta = $this->post('/bancos/transferir', [
            'origen_tipo' => 'banco',
            'origen_id' => $origen->id,
            'destino_tipo' => 'banco',
            'destino_id' => $destino->id,
            'monto' => 200,
            'comision' => 10,
            'concepto' => 'Reposición de cuenta LAFISE',
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');

        $origen->refresh();
        $destino->refresh();
        $this->assertSame(790.0, (float) $origen->saldo_actual);   // 1000 − 200 − 10 de comisión
        $this->assertSame(200.0, (float) $destino->saldo_actual);

        // Tres movimientos: retiro, cargo (comisión) y depósito.
        $this->assertSame(1, MovimientoBancario::where('cuenta_bancaria_id', $origen->id)->where('tipo', 'retiro')->count());
        $this->assertSame(1, MovimientoBancario::where('cuenta_bancaria_id', $origen->id)->where('tipo', 'cargo')->count());
        $this->assertSame(1, MovimientoBancario::where('cuenta_bancaria_id', $destino->id)->where('tipo', 'deposito')->count());

        // Asiento: entra a LAFISE, sale de BAC, y la comisión va al gasto 6.9.
        $movimientoDestino = MovimientoBancario::where('cuenta_bancaria_id', $destino->id)->where('tipo', 'deposito')->first();
        $lineas = $this->detallesDeAsiento($movimientoDestino?->asiento_contable_id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '1.1.2.1', debe: 200.0);
        $this->assertLinea($lineas, '1.1.2.2', haber: 210.0); // 200 salida + 10 comisión
        $this->assertLinea($lineas, '6.9', debe: 10.0);
    }

    public function test_transferencia_entre_moneda_distinta_es_rechazada(): void
    {
        $origen = $this->crearCuentaBancaria(1000, bancoCodigo: 'BAC');
        $destino = $this->crearCuentaBancaria(0, usd: true, bancoCodigo: 'BAC');

        $this->from('/bancos')->post('/bancos/transferir', [
            'origen_tipo' => 'banco',
            'origen_id' => $origen->id,
            'destino_tipo' => 'banco',
            'destino_id' => $destino->id,
            'monto' => 100,
            'concepto' => 'Intento NIO → USD',
        ])->assertSessionHasErrors('error');

        $origen->refresh();
        $destino->refresh();
        $this->assertSame(1000.0, (float) $origen->saldo_actual);
        $this->assertSame(0.0, (float) $destino->saldo_actual);
        $this->assertSame(0, Transferencia::count());
    }

    public function test_cargo_bancario_va_al_gasto_6_9_y_ajusta_saldo(): void
    {
        $cuenta = $this->crearCuentaBancaria(1000);
        $cuentaGasto = CuentaContable::where('codigo', '6.9')->first();

        $respuesta = $this->post('/bancos/cargo', [
            'cuenta_bancaria_id' => $cuenta->id,
            'monto' => 50,
            'concepto' => 'Mantenimiento mensual de cuenta',
            'cuenta_contable_id' => $cuentaGasto->id,
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');

        $cuenta->refresh();
        $this->assertSame(950.0, (float) $cuenta->saldo_actual);

        $movimiento = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->first();
        $this->assertSame('cargo', $movimiento->tipo);
        $this->assertNotNull($movimiento->asiento_contable_id);

        $lineas = $this->detallesDeAsiento($movimiento->asiento_contable_id);
        $this->assertCuadrado($lineas);
        $this->assertLinea($lineas, '6.9', debe: 50.0);
        $this->assertLinea($lineas, '1.1.2.2', haber: 50.0);
    }

    public function test_abono_bancario_requiere_cuenta_de_ingreso(): void
    {
        $cuenta = $this->crearCuentaBancaria(1000);
        $cuentaGasto = CuentaContable::where('codigo', '6.1')->first(); // gasto: inválido para abono

        $this->from('/bancos')->post('/bancos/abono', [
            'cuenta_bancaria_id' => $cuenta->id,
            'monto' => 25,
            'concepto' => 'Interés',
            'cuenta_contable_id' => $cuentaGasto->id,
        ])->assertSessionHasErrors('error');

        $cuenta->refresh();
        $this->assertSame(1000.0, (float) $cuenta->saldo_actual);
        $this->assertSame(0, MovimientoBancario::count());
    }

    public function test_deposito_con_efectivo_teorico_insuficiente_es_rechazado(): void
    {
        $caja = $this->crearCaja(100);
        $cuenta = $this->crearCuentaBancaria(0);

        $this->from('/bancos')->post('/bancos/depositar', [
            'cuenta_destino_id' => $cuenta->id,
            'monto' => 500, // apertura sólo 100
            'caja_origen_id' => $caja->id,
            'moneda' => 'nio',
        ])->assertSessionHasErrors('error');

        $cuenta->refresh();
        $this->assertSame(0.0, (float) $cuenta->saldo_actual);
        $this->assertSame(0, Transferencia::count());
        $this->assertSame(0, MovimientoBancario::count());
    }

    // =====================================================================
    // CONCILIACIÓN BANCARIA
    // =====================================================================

    public function test_conciliacion_con_diferencia_es_rechazada(): void
    {
        $caja = $this->crearCaja(500);
        $cuenta = $this->crearCuentaBancaria(0);

        $this->post('/bancos/depositar', [
            'cuenta_destino_id' => $cuenta->id,
            'monto' => 300,
            'caja_origen_id' => $caja->id,
            'moneda' => 'nio',
        ]);

        $movimiento = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->first();

        // El extracto dice C$ 999 pero el libro conciliado suma C$ 300: NO se cierra.
        $this->from('/bancos/' . $cuenta->id . '/conciliacion')
            ->post('/bancos/' . $cuenta->id . '/conciliacion', [
                'fecha' => now()->toDateString(),
                'saldo_extracto' => 999,
                'movimientos' => [$movimiento->id],
            ])
            ->assertSessionHasErrors('error');

        $this->assertSame(0, ConciliacionBancaria::count());

        $movimiento->refresh();
        $this->assertNotSame('conciliado', $movimiento->estado_conciliacion);
    }

    public function test_conciliacion_cuadrada_se_cierra_y_marca_movimientos(): void
    {
        $caja = $this->crearCaja(500);
        $cuenta = $this->crearCuentaBancaria(0);

        $this->post('/bancos/depositar', [
            'cuenta_destino_id' => $cuenta->id,
            'monto' => 300,
            'caja_origen_id' => $caja->id,
            'moneda' => 'nio',
        ]);

        $movimiento = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->first();

        // Extracto real: C$ 300. El depósito ya pasó por el banco.
        $respuesta = $this->post('/bancos/' . $cuenta->id . '/conciliacion', [
            'fecha' => now()->toDateString(),
            'saldo_extracto' => 300,
            'movimientos' => [$movimiento->id],
            'observaciones' => 'Extracto revisado',
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');

        $conciliacion = ConciliacionBancaria::first();
        $this->assertNotNull($conciliacion);
        $this->assertSame('cerrada', $conciliacion->estado);
        $this->assertSame(0.0, (float) $conciliacion->diferencia);
        $this->assertSame(300.0, (float) $conciliacion->saldo_extracto);
        $this->assertSame(300.0, (float) $conciliacion->saldo_libros);

        $movimiento->refresh();
        $this->assertTrue((bool) $movimiento->conciliado);
        $this->assertSame('conciliado', $movimiento->estado_conciliacion);
        $this->assertNotNull($movimiento->fecha_conciliacion);
        $this->assertSame($conciliacion->id, $movimiento->conciliacion_id);
    }

    public function test_conciliacion_deja_cheques_en_transito(): void
    {
        $caja = $this->crearCaja(500);
        $cuenta = $this->crearCuentaBancaria(0);

        // Depósito que SÍ pasó por el banco.
        $this->post('/bancos/depositar', [
            'cuenta_destino_id' => $cuenta->id,
            'monto' => 300,
            'caja_origen_id' => $caja->id,
            'moneda' => 'nio',
        ]);

        // Retiro (cheque emitido) que AÚN no pasa por el banco.
        $this->post('/bancos/transferir', [
            'origen_tipo' => 'banco',
            'origen_id' => $cuenta->id,
            'destino_tipo' => 'sesion',
            'destino_id' => $caja->id,
            'monto' => 100,
            'concepto' => 'Cheque para gastos del turno',
        ]);

        $deposito = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->where('tipo', 'deposito')->first();
        $cheque = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->where('tipo', 'retiro')->first();

        // Extracto C$ 300: sólo el depósito ya aclarado; el cheque queda en tránsito.
        $this->post('/bancos/' . $cuenta->id . '/conciliacion', [
            'fecha' => now()->toDateString(),
            'saldo_extracto' => 300,
            'movimientos' => [$deposito->id],
        ])->assertSessionHas('success');

        $cheque->refresh();
        $deposito->refresh();
        $this->assertSame('conciliado', $deposito->estado_conciliacion);
        $this->assertSame('en_transito', $cheque->estado_conciliacion);
    }
}
