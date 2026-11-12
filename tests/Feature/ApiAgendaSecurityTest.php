<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Rol;
use App\Models\Servicio;
use App\Models\Usuario;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Solo H12: fixtures locales, ninguna red, exportación o servicio externo. */
class ApiAgendaSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'h12-fixture-csrf-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // Conserva el middleware real salvo su bypass exclusivo del runner.
        $this->app->bind(PreventRequestForgery::class, EnforcedAgendaCsrf::class);
        $this->withSession(['_token' => self::TOKEN]);
        $this->withHeaders(['Accept' => 'application/json', 'X-CSRF-TOKEN' => self::TOKEN]);
    }

    private function user(string $role = 'recepcion'): Usuario
    {
        return Usuario::create([
            'name' => 'H12 '.$role, 'email' => uniqid('h12').'@example.test',
            'password' => 'fixture-password', 'role' => $role,
            'role_id' => Rol::firstOrFail()->id, 'is_active' => true,
        ]);
    }

    private function fixture(): array
    {
        $client = Cliente::create(['name' => 'Cliente H12']);
        $stylist = $this->user('estilista');
        // Compatibilidad heredada explícita: ambos catálogos tienen el mismo ID.
        // No cambia FK ni oculta el caso de IDs divergentes probado más abajo.
        $item = Articulo::create(['producto' => 'Item H12', 'type' => 'servicio', 'precio_c' => 100, 'existencia_actual' => 0]);
        $service = new Servicio;
        $service->id = $item->id;
        $service->fill(['name' => 'Servicio H12', 'price' => 100, 'duration' => 45, 'is_active' => true])->save();
        return ['client_id' => $client->id, 'service_id' => $item->id,
            'stylists' => [$stylist->id], 'appointment_date' => today()->setHour(10)->toDateTimeString(), 'duration_minutes' => 45];
    }

    public function test_guest_cannot_read_clients_or_agenda(): void
    {
        $client = Cliente::create(['name' => 'Dato H12 privado']);
        foreach (['/api/clients', '/api/clients/'.$client->id, '/api/appointments', '/api/items'] as $url) {
            $this->get($url)->assertUnauthorized()->assertDontSee('Dato H12 privado');
        }
    }

    public function test_guest_cannot_mutate_clients_or_appointments_even_with_csrf(): void
    {
        $data = $this->fixture();
        $appointment = Cita::create(array_merge($data, ['status' => 'pendiente']));
        $before = Cita::count();
        $this->post('/api/appointments', $data)->assertUnauthorized();
        $this->put('/api/appointments/'.$appointment->id, ['status' => 'completada'])->assertUnauthorized();
        $this->post('/api/clients', ['name' => 'Intruso'])->assertUnauthorized();
        $this->put('/api/clients/'.$data['client_id'], ['name' => 'Intruso'])->assertUnauthorized();
        $this->delete('/api/clients/'.$data['client_id'])->assertUnauthorized();
        $this->assertSame($before, Cita::count());
        $this->assertSame('pendiente', $appointment->fresh()->status);
        $this->assertDatabaseMissing('clients', ['name' => 'Intruso']);
    }

    public function test_all_existing_roles_keep_general_access_and_administrative_limits(): void
    {
        $client = Cliente::create(['name' => 'Cliente accesible']);
        foreach (['alvaro', 'admin', 'contador', 'recepcion', 'estilista'] as $role) {
            $this->actingAs($this->user($role));
            $this->get('/api/clients/'.$client->id)->assertOk()->assertJsonPath('cliente.name', 'Cliente accesible');
            $this->get('/api/appointments')->assertOk();
        }
        $this->get('/nomina')->assertForbidden(); // último rol: estilista
        $this->actingAs($this->user('admin'));
        $this->get('/catalogo')->assertForbidden(); // admin no tiene bypass contable
    }

    public function test_authenticated_agenda_creates_and_updates_the_bound_appointment(): void
    {
        $data = $this->fixture();
        $this->actingAs($this->user());
        $this->post('/api/appointments', $data)->assertCreated();
        $appointment = Cita::firstOrFail();
        $this->assertSame($data['stylists'][0], $appointment->estilistas()->firstOrFail()->id);
        $this->get('/agenda')->assertOk()->assertSee('Servicio H12')->assertSee('X-CSRF-TOKEN');
        $this->put('/api/appointments/'.$appointment->id, ['status' => 'completada'])->assertOk();
        $this->assertSame('completada', $appointment->fresh()->status);
        $this->delete('/api/clients/'.$data['client_id'])->assertUnprocessable();
        $this->put('/api/appointments/'.$appointment->id, ['status' => 'desconocido'])->assertUnprocessable();
        $this->put('/api/appointments/99999999', ['status' => 'completada'])->assertNotFound();
    }

    public function test_real_csrf_middleware_rejects_missing_and_invalid_tokens(): void
    {
        $data = $this->fixture();
        $this->actingAs($this->user());
        $this->withHeader('X-CSRF-TOKEN', '')->post('/api/appointments', $data)->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'incorrecto')->post('/api/appointments', $data)->assertStatus(419);
        $this->assertSame(0, Cita::count());
        $this->withHeader('X-CSRF-TOKEN', self::TOKEN)->post('/api/appointments', $data)->assertCreated();
        $appointment = Cita::firstOrFail();
        $this->withHeader('X-CSRF-TOKEN', '')->put('/api/appointments/'.$appointment->id, ['status' => 'completada'])->assertStatus(419);
        $this->assertSame('pendiente', $appointment->fresh()->status);
        $this->post('/api/clients', ['name' => 'Sin token'])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', self::TOKEN)->post('/api/clients', ['name' => 'Con token'])->assertOk();
        $this->assertDatabaseHas('clients', ['name' => 'Con token']);
    }

    public function test_session_cookie_authentication_works_without_acting_as(): void
    {
        $user = $this->user();
        $this->withSession(['_token' => self::TOKEN, $this->app['auth']->guard('web')->getName() => $user->id]);
        $this->get('/api/appointments')->assertOk();
    }

    public function test_authenticated_client_edits_keep_api_and_web_urls(): void
    {
        $client = Cliente::create(['name' => 'Antes']);
        $this->actingAs($this->user());
        $this->put('/api/clients/'.$client->id, ['name' => 'API'])->assertOk();
        $this->get('/clientes/'.$client->id)->assertOk()->assertJsonPath('cliente.name', 'API');
        $this->put('/clientes/'.$client->id, ['name' => 'Web'])->assertOk();
        $this->get('/api/clients/'.$client->id)->assertOk()->assertJsonPath('cliente.name', 'Web');
    }

    public function test_services_only_id_still_exposes_the_documented_legacy_catalog_mismatch(): void
    {
        $data = $this->fixture();
        $service = Servicio::create(['name' => 'Solo services', 'price' => 200, 'duration' => 45, 'is_active' => true]);
        $this->assertDatabaseMissing('items', ['id' => $service->id]);
        $this->actingAs($this->user());
        $this->post('/api/appointments', array_replace($data, ['service_id' => $service->id]))->assertUnprocessable();
        $this->assertSame(0, Cita::count());
    }
}

class EnforcedAgendaCsrf extends PreventRequestForgery
{
    protected function runningUnitTests() { return false; }
}
