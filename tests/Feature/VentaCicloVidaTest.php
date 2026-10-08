<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\ComisionGenerada;
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
 * CICLO DE VIDA DEL DOCUMENTO (estados y anulaciones).
 *
 * Verifica el workflow de factura: borrador (cotización sin efectos), emitida
 * (crédito con CxC y cobro posterior), pagada y anulada (sin borrado físico,
 * con reversión contable, retorno de inventario y devolución de divisas).
 */
class VentaCicloVidaTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $cajero;

    protected Usuario $admin;

    protected Servicio $servicio;

    protected Cliente $cliente;

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
            // El AUTO_INCREMENT de roles no revierte entre pruebas (transacciones)
            'role_id' => Rol::where('name', 'Recepción / Caja')->first()->id,
            'is_active' => true,
        ]);

        // También actúa como estilista (comisión del 10%) para probar el
        // candado de nómina y la anulación de comisiones pendientes.
        $this->admin = Usuario::create([
            'name' => 'Admin Prueba',
            'email' => 'admin@test.com',
            'password' => 'password',
            'role' => 'admin',
            'role_id' => Rol::where('name', 'Administrador')->first()->id,
            'is_active' => true,
            'comision_servicio' => 10,
        ]);

        $this->servicio = Servicio::create([
            'name' => 'Maquillaje Social',
            'price' => 3500,
            'duration' => 60,
            'is_active' => true,
        ]);

        $this->cliente = Cliente::create(['name' => 'Cliente Cotización']);

        $this->actingAs($this->cajero);
        SesionCaja::create([
            'user_id' => $this->cajero->id,
            'monto_apertura' => 0,
            'estado' => 'abierta',
            'fecha_apertura' => now(),
        ]);
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

    protected function crearVentaPagada(): Venta
    {
        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 50, 'tasa' => 36.50],
                ['metodo' => 'bac', 'moneda' => 'nio', 'monto' => 1675, 'tasa' => 1],
            ],
        ])->assertStatus(201);

        return Venta::first();
    }

    public function test_borrador_no_afecta_inventario_pagos_ni_contabilidad(): void
    {
        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'client_id' => $this->cliente->id,
            'action' => 'cotizar',
        ]);

        $response->assertStatus(201);

        $venta = Venta::first();
        $this->assertEquals(Venta::ESTADO_BORRADOR, $venta->status);
        $this->assertEquals(1, $venta->detalles()->count());

        // Sin efectos financieros ni de inventario
        $this->assertEquals(0, Pago::count());
        $this->assertEquals(0, DB::table('asientos_contables')->count());
        $this->assertEquals(0, ComisionGenerada::count());
        $this->assertEquals(0, SaldoMoneda::count());
    }

    public function test_borrador_se_transforma_en_venta_reutilizando_el_mismo_documento(): void
    {
        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'client_id' => $this->cliente->id,
            'action' => 'cotizar',
        ])->assertStatus(201);

        $cotizacion = Venta::first();

        // La clienta acepta: se cobra la MISMA cotización (draft_id)
        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'client_id' => $this->cliente->id,
            'action' => 'pagar',
            'draft_id' => $cotizacion->id,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 3500, 'tasa' => 1],
            ],
        ])->assertStatus(201);

        $this->assertEquals(1, Venta::count()); // no se creó un documento nuevo
        $cotizacion->refresh();
        $this->assertEquals(Venta::ESTADO_PAGADA, $cotizacion->status);
        $this->assertEquals(1, $cotizacion->pagos()->where('tipo', 'pago')->count());
    }

    public function test_credito_genera_cxc_y_el_cobro_posterior_la_cancela(): void
    {
        $response = $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'client_id' => $this->cliente->id,
            'action' => 'credito',
        ]);

        $response->assertStatus(201);

        $venta = Venta::first();
        $this->assertEquals(Venta::ESTADO_EMITIDA, $venta->status);
        $this->assertEquals(0, $venta->pagos()->count());

        // Asiento ING: DEBE CxC (1.1.3) / HABER ingresos
        $cxc = DB::table('cuentas_contables')->where('codigo', '1.1.3')->first();
        $asiento = DB::table('asientos_contables')
            ->where('modulo_origen', 'ventas')->where('referencia_id', $venta->id)->first();

        $this->assertDatabaseHas('detalle_asientos', [
            'asiento_id' => $asiento->id, 'cuenta_id' => $cxc->id, 'debe' => 3500.00,
        ]);

        // Cobro posterior con pago mixto
        $this->postJson('/ventas/'.$venta->id.'/cobrar', [
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 50, 'tasa' => 36.50],
                ['metodo' => 'lafise', 'moneda' => 'nio', 'monto' => 1675, 'tasa' => 1],
            ],
        ])->assertStatus(200);

        $venta->refresh();
        $this->assertEquals(Venta::ESTADO_PAGADA, $venta->status);

        // Asiento COB: cuadrado y cancela la CxC por el total
        $asientoCobro = DB::table('asientos_contables')
            ->where('referencia_id', $venta->id)
            ->where('numero_asiento', 'like', 'COB-%')
            ->first();

        $totales = DB::table('detalle_asientos')->where('asiento_id', $asientoCobro->id)
            ->selectRaw('SUM(debe) d, SUM(haber) h')->first();

        $this->assertEquals(3500.00, (float) $totales->d);
        $this->assertEquals(3500.00, (float) $totales->h);
        $this->assertDatabaseHas('detalle_asientos', [
            'asiento_id' => $asientoCobro->id, 'cuenta_id' => $cxc->id, 'haber' => 3500.00,
        ]);

        // Los USD del cobro entraron al inventario físico de divisas
        $saldoUsd = SaldoMoneda::where('ubicacion_tipo', SesionCaja::class)->first();
        $this->assertEquals(50.00, (float) $saldoUsd->saldo_actual);
    }

    public function test_credito_sin_cliente_es_rechazado(): void
    {
        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'action' => 'credito',
        ])->assertStatus(422);

        $this->assertEquals(0, Venta::count());
    }

    public function test_anular_factura_pagada_revierte_todo_sin_borrar_el_documento(): void
    {
        // Producto físico con stock para verificar el retorno de inventario
        $articulo = Articulo::create([
            'producto' => 'Shampoo Prueba',
            'type' => 'producto_venta',
            'precio_c' => 100,
            'precio_usd' => 3,
            'existencia_actual' => 10,
            'stock_min' => 1,
        ]);

        $cart = $this->carrito();
        $cart[0]['stylist_id'] = $this->admin->id; // genera comisión pendiente (10%)
        $cart[] = ['id' => $articulo->id, 'precio_c' => 500, 'quantity' => 2, 'type' => 'producto'];

        $this->postJson('/sales', [
            'cart' => $cart,
            'discount' => 0,
            'payments' => [
                ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 2675, 'tasa' => 1],
                ['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 50, 'tasa' => 36.50],
            ],
        ])->assertStatus(201); // total 4500 = 2675 + 1825 exacto

        $venta = Venta::first();
        $articulo->refresh();
        $this->assertEquals(8, (float) $articulo->existencia_actual);
        $this->assertEquals(1, ComisionGenerada::where('venta_id', $venta->id)->where('estado', 'pendiente')->count());

        $asientosAntes = DB::table('asientos_contables')->where('referencia_id', $venta->id)->count();
        $this->assertGreaterThan(0, $asientosAntes);

        // El ADMIN anula con motivo
        $this->actingAs($this->admin);
        $this->from('/historial-ventas')->post('/ventas/'.$venta->id.'/anular', [
            'motivo' => 'Cliente canceló el servicio completo',
        ])->assertRedirect('/historial-ventas');

        $venta->refresh();

        // El documento NO se borró: cambió de estado con auditoría
        $this->assertEquals(Venta::ESTADO_ANULADA, $venta->status);
        $this->assertEquals('Cliente canceló el servicio completo', $venta->motivo_anulacion);
        $this->assertEquals($this->admin->id, $venta->anulada_por);
        $this->assertNotNull($venta->anulada_at);

        // El inventario regresó físicamente
        $articulo->refresh();
        $this->assertEquals(10, (float) $articulo->existencia_actual);

        // Los USD cobrados se devolvieron al cliente (saldo físico en cero)
        $saldoUsd = SaldoMoneda::where('ubicacion_tipo', SesionCaja::class)->first();
        $this->assertEquals(0.00, (float) $saldoUsd->saldo_actual);

        // La comisión pendiente quedó anulada
        $this->assertEquals('anulada', ComisionGenerada::where('venta_id', $venta->id)->first()->estado);

        // Cada asiento original tiene su espejo REV- cuadrado
        $reversas = DB::table('asientos_contables')
            ->where('referencia_id', $venta->id)
            ->where('numero_asiento', 'like', 'REV-%')
            ->get();
        $this->assertEquals($asientosAntes, $reversas->count());

        foreach ($reversas as $rev) {
            $totales = DB::table('detalle_asientos')->where('asiento_id', $rev->id)
                ->selectRaw('SUM(debe) d, SUM(haber) h')->first();
            $this->assertEquals((float) $totales->d, (float) $totales->h);
        }

        // El efecto contable neto de la factura quedó en cero
        $neto = DB::table('asientos_contables')
            ->join('detalle_asientos', 'detalle_asientos.asiento_id', '=', 'asientos_contables.id')
            ->where('asientos_contables.referencia_id', $venta->id)
            ->selectRaw('SUM(debe) - SUM(haber) as neto')->value('neto');
        $this->assertEquals(0.00, round((float) $neto, 2));

        // Y la venta anulada ya no suma en el efectivo del turno
        $efectivo = Venta::efectivoNioNeto(
            Venta::where('status', Venta::ESTADO_PAGADA)->where('cashier_id', $this->cajero->id)
        );
        $this->assertEquals(0.00, $efectivo);
    }

    public function test_anular_borrador_solo_cambia_estado_sin_efectos(): void
    {
        $this->postJson('/sales', [
            'cart' => $this->carrito(),
            'discount' => 0,
            'action' => 'cotizar',
        ])->assertStatus(201);

        $venta = Venta::first();

        $this->actingAs($this->admin);
        $this->from('/historial-ventas')->post('/ventas/'.$venta->id.'/anular', [
            'motivo' => 'Cotización duplicada por error',
        ])->assertRedirect('/historial-ventas');

        $this->assertEquals(Venta::ESTADO_ANULADA, $venta->fresh()->status);
        $this->assertEquals(0, DB::table('asientos_contables')->count());
    }

    public function test_anular_requiere_rol_administrador(): void
    {
        $venta = $this->crearVentaPagada();

        // La recepcionista NO puede anular (la ruta exige role:admin)
        $this->post('/ventas/'.$venta->id.'/anular', [
            'motivo' => 'Intento sin permiso suficiente',
        ])->assertStatus(403);

        $this->assertEquals(Venta::ESTADO_PAGADA, $venta->fresh()->status);
    }

    public function test_doble_anulacion_es_rechazada(): void
    {
        $venta = $this->crearVentaPagada();

        $this->actingAs($this->admin);
        $this->from('/historial-ventas')->post('/ventas/'.$venta->id.'/anular', [
            'motivo' => 'Primera anulación válida',
        ])->assertRedirect('/historial-ventas');

        $response = $this->from('/historial-ventas')->post('/ventas/'.$venta->id.'/anular', [
            'motivo' => 'Segundo intento duplicado',
        ]);

        $response->assertRedirect('/historial-ventas');
        $response->assertSessionHas('error');

        // No se generaron reversas duplicadas
        $reversas = DB::table('asientos_contables')->where('numero_asiento', 'like', 'REV-%')->count();
        $this->assertEquals(1, $reversas);
    }

    public function test_anulacion_bloqueada_si_comision_ya_fue_pagada_en_nomina(): void
    {
        $cart = $this->carrito();
        $cart[0]['stylist_id'] = $this->admin->id;

        $this->postJson('/sales', [
            'cart' => $cart,
            'discount' => 0,
            'payments' => [['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 3500, 'tasa' => 1]],
        ])->assertStatus(201);

        $venta = Venta::first();

        // Simular que la nómina ya pagó la comisión de esta venta
        ComisionGenerada::where('venta_id', $venta->id)->update(['estado' => 'pagada']);

        $this->actingAs($this->admin);
        $response = $this->from('/historial-ventas')->post('/ventas/'.$venta->id.'/anular', [
            'motivo' => 'Intento posterior a nómina',
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals(Venta::ESTADO_PAGADA, $venta->fresh()->status);
        $this->assertEquals(0, DB::table('asientos_contables')->where('numero_asiento', 'like', 'REV-%')->count());
    }
}
