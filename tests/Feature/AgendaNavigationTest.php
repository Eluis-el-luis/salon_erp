<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Rol;
use App\Models\Servicio;
use App\Models\Usuario;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** H14-A: fixtures SQL locales, sin llamadas externas ni conexiones alternativas. */
class AgendaNavigationTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $user = Usuario::create([
            'name' => 'Estilista H14', 'email' => 'h14@example.test',
            'password' => 'fixture-password', 'role' => 'estilista',
            'role_id' => Rol::firstOrFail()->id, 'is_active' => true,
        ]);
        $client = Cliente::create(['name' => 'Cliente H14']);
        $service = Servicio::create(['name' => 'Servicio H14', 'price' => 100, 'duration' => 45, 'is_active' => true]);
        $this->payload = [
            'client_id' => $client->id, 'service_id' => $service->id,
            'stylists' => [$user->id], 'appointment_date' => '2026-11-13T10:00', 'duration_minutes' => 45,
        ];
        $this->actingAs($user);
        $this->app->bind(PreventRequestForgery::class, EnforcedH14Csrf::class);
        $this->withSession(['_token' => 'h14-csrf']);
        $this->withHeaders(['Accept' => 'application/json', 'X-CSRF-TOKEN' => 'h14-csrf']);
    }

    public function test_two_appointments_for_the_same_stylist_and_saved_confirmation(): void
    {
        $first = $this->post('/api/appointments', $this->payload)->assertCreated();
        $second = $this->post('/api/appointments', array_replace($this->payload, ['appointment_date' => '2026-11-13T12:00']))->assertCreated();
        $id = $second->json('appointment.id');
        $this->assertNotSame($first->json('appointment.id'), $id);
        $this->assertSame('2026-11-13 12:00:00', $second->json('appointment.appointment_date'));
        $this->assertDatabaseHas('appointments', ['id' => $id, 'appointment_date' => '2026-11-13 12:00:00']);
        $this->assertSame(2, DB::table('appointment_user')->where('user_id', $this->payload['stylists'][0])->count());
    }

    public function test_selected_dates_include_past_and_future_and_default_uses_salon_today(): void
    {
        // UTC ya es 14, mientras en Managua sigue siendo el día 13.
        $this->travelTo(\Carbon\Carbon::parse('2026-11-14 02:30:00', 'UTC'));
        try {
            $ids = [];
            foreach (['2026-11-12', '2026-11-13', '2026-11-14'] as $date) {
                $ids[$date] = $this->post('/api/appointments', array_replace($this->payload, ['appointment_date' => $date.'T23:00']))->assertCreated()->json('appointment.id');
            }
            $this->get('/api/appointments')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $ids['2026-11-13']);
            $this->get('/agenda')->assertOk()->assertViewHas('fechaSeleccionada', '2026-11-13')->assertSee('13/11/2026');
            foreach ($ids as $date => $id) {
                $this->get('/api/appointments?fecha='.$date)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);
                $this->get('/agenda?fecha='.$date)->assertOk()->assertViewHas('fechaSeleccionada', $date)
                    ->assertViewHas('citas', fn ($citas) => $citas->pluck('id')->all() === [$id]);
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_invalid_dates_are_rejected_in_both_listings(): void
    {
        foreach (['2026-02-30', '13/11/2026', 'not-a-date', '', '2026-11-13T10:00', '2026-11-13Z', ['2026-11-13']] as $date) {
            foreach (['/agenda', '/api/appointments'] as $url) {
                $response = $this->get($url.'?'.http_build_query(['fecha' => $date]))->assertUnprocessable();
                if ($url === '/api/appointments') {
                    $response->assertJsonValidationErrors('fecha');
                }
            }
        }
    }

    public function test_association_failure_rolls_back_the_appointment_and_pivot(): void
    {
        $before = Cita::count();
        $pivotBefore = DB::table('appointment_user')->count();
        $associationReached = false;
        DB::listen(function (QueryExecuted $query) use (&$associationReached) {
            if (str_starts_with(strtolower($query->sql), 'insert into `appointment_user`')) {
                $associationReached = true;
                throw new \RuntimeException('Fallo simulado de asociación H14');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/api/appointments', $this->payload);
            $this->fail('Se esperaba el fallo de asociación.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fallo simulado de asociación H14', $error->getMessage());
        }
        $this->assertTrue($associationReached);
        $this->assertSame($before, Cita::count());
        $this->assertSame($pivotBefore, DB::table('appointment_user')->count());
    }
}

class EnforcedH14Csrf extends PreventRequestForgery
{
    protected function runningUnitTests() { return false; }
}
