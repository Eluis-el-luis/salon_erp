<?php

namespace Tests\Feature;

use App\Models\{Articulo, Cita, Cliente, Rol, Servicio, SesionCaja, Usuario, Venta};
use Database\Seeders\{ContabilidadBaseSeeder, CuentasComplementariasSeeder, RoleSeeder};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** H13: fixtures locales. Ningún acceso externo ni conexión alternativa. */
class AppointmentServicesTest extends TestCase
{
    use RefreshDatabase;

    private Servicio $service;
    private Articulo $product;
    private array $payload;
    private Usuario $admin;
    private array $stylists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->user('admin', 'admin');
        $this->stylists = [$this->user('estilista', 'uno'), $this->user('estilista', 'dos')];
        $this->service = new Servicio;
        $this->service->id = 130001;
        $this->service->fill(['name' => 'Servicio comercial H13', 'price' => 200, 'duration' => 45, 'is_active' => true])->save();
        $this->product = new Articulo;
        $this->product->id = 230001;
        $this->product->fill(['producto' => 'Producto físico H13', 'type' => 'producto_venta', 'precio_c' => 50])->save();
        $client = Cliente::create(['name' => 'Cliente H13']);
        $this->payload = ['client_id' => $client->id, 'service_id' => $this->service->id,
            'stylists' => array_map(fn ($user) => $user->id, $this->stylists),
            'appointment_date' => '2026-11-20T10:00', 'duration_minutes' => 45];
        $this->actingAs($this->admin);
        $this->app->bind(PreventRequestForgery::class, EnforcedH13Csrf::class);
        $this->withSession(['_token' => 'h13-fixture']);
        $this->withHeaders(['Accept' => 'application/json', 'X-CSRF-TOKEN' => 'h13-fixture']);
    }

    private function user(string $role, string $suffix): Usuario
    {
        return Usuario::create(['name' => 'H13 '.$suffix, 'email' => $suffix.'@h13.example.test',
            'password' => 'fixture-password', 'role' => $role, 'role_id' => Rol::firstOrFail()->id, 'is_active' => true]);
    }

    public function test_services_only_and_inverse_relations_with_multiple_stylists(): void
    {
        $this->assertDatabaseMissing('items', ['id' => $this->service->id]);
        $id = $this->post('/api/appointments', $this->payload)->assertCreated()->json('appointment.id');
        $this->assertSame($id, $this->service->citas()->firstOrFail()->id);
        $this->assertSame(2, Cita::findOrFail($id)->estilistas()->count());
        foreach ($this->stylists as $stylist) {
            $this->assertSame($id, $stylist->citas()->firstOrFail()->id);
        }
    }

    public function test_items_only_is_rejected_by_validation_and_mysql_fk(): void
    {
        $this->assertDatabaseMissing('services', ['id' => $this->product->id]);
        $this->post('/api/appointments', array_replace($this->payload, ['service_id' => $this->product->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('service_id');
        try {
            Cita::create(array_replace($this->payload, ['service_id' => $this->product->id]));
            $this->fail('MySQL debía rechazar el producto como servicio.');
        } catch (QueryException $error) {
            $this->assertSame(1452, $error->errorInfo[1]);
        }
        $this->assertSame(0, Cita::count());
    }

    public function test_numeric_collision_keeps_the_commercial_service_and_restricts_deletion(): void
    {
        // Colisión deliberada, no una correspondencia de fixtures.
        $collision = new Articulo;
        $collision->id = $this->service->id;
        $collision->fill(['producto' => 'Otro producto sin relación', 'type' => 'producto_venta', 'precio_c' => 999])->save();
        $id = $this->post('/api/appointments', $this->payload)->assertCreated()->json('appointment.id');
        $this->assertSame('Servicio comercial H13', Cita::findOrFail($id)->servicio->name);
        $this->get('/agenda?fecha=2026-11-20')->assertOk()->assertSee('Servicio comercial H13')->assertDontSee('Otro producto sin relación');
        try {
            $this->service->delete();
            $this->fail('No se debe borrar un servicio con citas.');
        } catch (QueryException $error) {
            $this->assertSame(1451, $error->errorInfo[1]);
        }
        $this->assertDatabaseHas('appointments', ['id' => $id, 'service_id' => $this->service->id]);
    }

    public function test_billing_uses_the_services_catalog_and_keeps_the_appointment_link(): void
    {
        // La política actual solo exime a alvaro de la retención por umbral.
        $this->admin->update(['role' => 'alvaro']);
        $this->actingAs($this->admin);
        $this->seed(ContabilidadBaseSeeder::class);
        $this->seed(CuentasComplementariasSeeder::class);
        SesionCaja::create(['user_id' => $this->admin->id, 'monto_apertura' => 0, 'estado' => 'abierta', 'fecha_apertura' => now()]);
        $id = $this->post('/api/appointments', $this->payload)->assertCreated()->json('appointment.id');
        $this->post('/sales/bill-appointment/'.$id, ['currency' => 'nio', 'payment_method' => 'efectivo'])->assertOk();
        $sale = Venta::where('appointment_id', $id)->firstOrFail();
        $this->assertSame(Venta::ESTADO_PAGADA, $sale->status);
        $this->assertSame(200.0, (float) $sale->total);
        $this->assertSame(2, $sale->detalles()->count());
        foreach ($sale->detalles as $detail) {
            $this->assertSame($this->service->id, $detail->service_id);
            $this->assertNull($detail->item_id);
        }
        $this->assertSame('completada', Cita::findOrFail($id)->status);
        $this->assertDatabaseMissing('items', ['id' => $this->service->id]);
    }
}

class EnforcedH13Csrf extends PreventRequestForgery
{
    protected function runningUnitTests() { return false; }
}
