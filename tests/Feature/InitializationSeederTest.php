<?php

namespace Tests\Feature;

use App\Models\{Articulo, Cita, Cliente, Empleado, FormulaServicio, Proveedor, Rol, Servicio, Usuario};
use Database\Seeders\{DatabaseSeeder, DemoSeeder, ServiceSeeder, UserSeeder};
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

/** Solo esquema MySQL YA preparado. Sin RefreshDatabase, migraciones, DDL o red. */
class InitializationSeederTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertFalse(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(74, DB::table('migrations')->count());
        $fk = DB::selectOne("SELECT REFERENCED_TABLE_NAME AS target FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND COLUMN_NAME='service_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
        $this->assertSame('services', $fk->target);
    }

    private function owner(): array
    {
        $data = ['name' => 'Propietario fixture', 'email' => 'owner@fixture.example.test',
            'password' => bin2hex(random_bytes(16)), 'pin' => (string) random_int(100000, 999999)];
        config(['salon.bootstrap_owner' => $data]);
        $this->seed(UserSeeder::class);
        return $data;
    }

    private function counts(): array
    {
        $counts = [];
        foreach (['roles', 'users', 'employees', 'providers', 'items', 'services', 'service_formulas', 'monedas', 'cuentas_contables', 'customer_groups', 'service_categories', 'tax_templates', 'salary_structures', 'salary_structure_details', 'leave_types', 'shifts', 'periodos_contables'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        return $counts;
    }

    public function test_essential_configuration_is_repeatable_and_not_demo_or_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = $this->counts();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($counts, $this->counts());
        $this->assertSame(5, Rol::count());
        $this->assertSame(5, Rol::distinct()->count('name'));
        foreach (['users', 'employees', 'items', 'services', 'providers', 'periodos_contables'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        foreach (['3.3', '1.1.1', '1.1.1.3', '1.1.7', '2.1.5', '2.1.6', '6.8', '6.9', '7.5'] as $code) {
            $this->assertDatabaseHas('cuentas_contables', ['codigo' => $code, 'is_system_account' => 1]);
        }
        $this->assertDatabaseHas('cuentas_contables', ['codigo' => '3.3', 'tipo' => 'patrimonio', 'naturaleza' => 'deudora', 'activa' => 1]);
        $this->assertDatabaseHas('cuentas_contables', ['codigo' => '7.2', 'activa' => 0, 'permite_movimiento' => 0]);
        $this->assertSame(0, DB::table('cuentas_contables')->where('is_system_account', false)->count());
    }

    public function test_owner_credentials_employee_and_existing_contract_survive_repetition(): void
    {
        $this->seed(DatabaseSeeder::class);
        $data = $this->owner();
        $owner = Usuario::where('email', $data['email'])->sole();
        $this->assertSame('alvaro', $owner->role);
        $this->assertTrue((bool) $owner->is_active);
        $this->assertSame(Rol::where('name', 'Álvaro (Propietario)')->sole()->id, $owner->role_id);
        $this->assertTrue(Hash::check($data['password'], $owner->password));
        $this->assertTrue(Hash::check($data['pin'], $owner->pin_seguridad));
        $passwordHash = $owner->password;
        $pinHash = $owner->pin_seguridad;
        $employee = Empleado::where('user_id', $owner->id)->sole();
        $employee->update(['salario_base' => 1234, 'tipo_contrato' => 'plazo_fijo']);
        config(['salon.bootstrap_owner.password' => bin2hex(random_bytes(16)), 'salon.bootstrap_owner.pin' => (string) random_int(100000, 999999)]);
        $this->seed(UserSeeder::class);
        $this->assertSame(1, Usuario::count());
        $this->assertSame(1, Empleado::count());
        $this->assertSame($passwordHash, $owner->fresh()->password);
        $this->assertSame($pinHash, $owner->fresh()->pin_seguridad);
        $this->assertSame(1234.0, (float) $employee->fresh()->salario_base);
        $this->assertSame('plazo_fijo', $employee->fresh()->tipo_contrato);
    }

    public function test_owner_requires_private_configuration_and_command_rejects_noninteractive_input(): void
    {
        config(['salon.bootstrap_owner' => null]);
        try {
            app(UserSeeder::class)->run();
            $this->fail('No debe existir una credencial predeterminada.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('salon:propietario', $error->getMessage());
        }
        $this->assertSame(0, Usuario::count());
        $this->artisan('salon:propietario', ['--no-interaction' => true])->assertFailed();
        $this->assertNull(config('salon.bootstrap_owner'));
    }

    public function test_demo_relations_use_seed_keys_not_assumed_ids_and_create_no_stock(): void
    {
        $this->seed(DatabaseSeeder::class);
        $next = (int) DB::selectOne("SELECT MAX(AUTO_INCREMENT) AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('items', 'services', 'providers')")->next_id + 100;
        $service = new Servicio;
        $service->id = $next;
        $service->fill(['name' => 'Fuera de demo', 'price' => 1, 'duration' => 5])->save();
        $item = new Articulo;
        $item->id = $next + 100;
        $item->fill(['codigo' => 'FUERA-DEMO', 'producto' => 'Fuera de demo', 'type' => 'producto_venta'])->save();
        $provider = new Proveedor;
        $provider->id = $next + 200;
        $provider->fill(['name' => 'Fuera de demo'])->save();
        $this->seed(DemoSeeder::class);
        $counts = $this->counts();
        $this->seed(DemoSeeder::class);
        $this->assertSame($counts, $this->counts());
        $keratin = Articulo::where('codigo', 'KER-001')->sole();
        $commercial = Servicio::where('name', ServiceSeeder::ALISADO)->sole();
        $this->assertNotSame($keratin->id, $commercial->id);
        $this->assertSame(Proveedor::where('name', 'Truss Professional')->sole()->id, $keratin->provider_id);
        $this->assertSame('producto_interno', $keratin->type);
        $this->assertSame('ml', $keratin->unit_measure);
        $this->assertSame(1000.0, (float) $keratin->total_volume);
        $this->assertSame(0, Articulo::where('existencia_actual', '<>', 0)->count());
        $this->assertSame(0, Articulo::where('current_volume', '<>', 0)->count());
        $this->assertDatabaseHas('service_formulas', ['service_id' => $commercial->id, 'item_id' => $keratin->id, 'quantity_used' => 60]);
        $this->assertSame(4, Usuario::count());
        $this->assertSame(4, Empleado::count());
        foreach (Usuario::all() as $user) {
            $expected = ['admin' => 'Administrador', 'contador' => 'Contador', 'recepcion' => 'Recepción / Caja', 'estilista' => 'Estilista'][$user->role];
            $this->assertSame(Rol::where('name', $expected)->sole()->id, $user->role_id);
            $this->assertTrue(Hash::isHashed($user->password));
        }
        foreach (['lotes', 'stock_ledger_entries', 'purchase_invoices', 'asientos_contables', 'sales', 'saldos_moneda'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_repeated_seeders_preserve_custom_settings_and_stock(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
        $item = Articulo::where('codigo', 'KER-001')->sole();
        // Simula una posición existente; el seeder no debe resetearla ni generar movimientos.
        $item->update(['existencia_actual' => 7, 'current_volume' => 25]);
        $formula = FormulaServicio::firstOrFail();
        $formula->update(['quantity_used' => 75]);
        DB::table('shifts')->where('nombre', 'Turno Completo')->update(['tolerancia_minutos' => 99]);
        DB::table('customer_groups')->where('name', 'VIP')->update(['discount_percent' => 13]);
        DB::table('salary_structure_details')->where('codigo', 'TARDANZAS')->update(['valor' => 77]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->assertSame(7.0, (float) $item->fresh()->existencia_actual);
        $this->assertSame(25.0, (float) $item->fresh()->current_volume);
        $this->assertSame(75.0, (float) $formula->fresh()->quantity_used);
        $this->assertDatabaseHas('shifts', ['nombre' => 'Turno Completo', 'tolerancia_minutos' => 99]);
        $this->assertDatabaseHas('customer_groups', ['name' => 'VIP', 'discount_percent' => 13]);
        $this->assertDatabaseHas('salary_structure_details', ['codigo' => 'TARDANZAS', 'valor' => 77]);
    }

    public function test_duplicate_roles_require_review_instead_of_automatic_merging(): void
    {
        $this->seed(DatabaseSeeder::class);
        Rol::create(['name' => 'Álvaro (Propietario)']);
        $before = $this->counts();
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Se debía rechazar el catálogo ambiguo.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('duplicados', $error->getMessage());
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_owner_setup_does_not_promote_an_existing_operational_user(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = Usuario::create(['name' => 'Operador fixture', 'email' => 'owner@fixture.example.test',
            'password' => bin2hex(random_bytes(16)), 'role' => 'admin',
            'role_id' => Rol::where('name', 'Administrador')->sole()->id]);
        $hash = $user->password;
        try {
            $this->owner();
            $this->fail('No debe promoverse una cuenta existente.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('no se modificó', $error->getMessage());
        }
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(0, Empleado::count());
    }

    public function test_incompatible_account_stops_initialization_without_rewriting_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('cuentas_contables')->where('codigo', '6.8')->update(['tipo' => 'ingreso']);
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('La configuración incompatible debía rechazarse.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('clasificación incompatible', $error->getMessage());
        }
        $this->assertDatabaseHas('cuentas_contables', ['codigo' => '6.8', 'tipo' => 'ingreso']);
    }

    public function test_h12_h14_and_h13_boundaries_on_existing_schema(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->app->bind(PreventRequestForgery::class, EnforcedBootstrapCsrf::class);
        $this->withSession(['_token' => 'bootstrap-csrf']);
        $this->withHeaders(['Accept' => 'application/json', 'X-CSRF-TOKEN' => 'bootstrap-csrf']);
        $this->get('/api/clients')->assertUnauthorized();
        $this->get('/api/appointments?fecha=2026-11-20')->assertUnauthorized();
        $this->owner();
        $this->actingAs(Usuario::where('role', 'alvaro')->sole());
        $account = DB::table('cuentas_contables')->where('codigo', '3.3')->first();
        $this->delete('/catalogo/'.$account->id)->assertForbidden();
        $this->put('/catalogo/'.$account->id, ['codigo' => '3.999', 'nombre' => 'Nombre local conservado'])->assertOk();
        $this->assertDatabaseHas('cuentas_contables', ['id' => $account->id, 'codigo' => '3.3']);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseHas('cuentas_contables', ['id' => $account->id, 'nombre' => 'Nombre local conservado']);
        $client = Cliente::create(['name' => 'Cliente fixture bootstrap']);
        $service = Servicio::where('name', ServiceSeeder::CORTE)->sole();
        $stylist = Usuario::where('role', 'estilista')->sole();
        $payload = ['client_id' => $client->id, 'service_id' => $service->id, 'stylists' => [$stylist->id], 'appointment_date' => '2026-11-20T10:00'];
        $this->withHeader('X-CSRF-TOKEN', '')->post('/api/appointments', $payload)->assertStatus(419);
        $this->assertSame(0, Cita::count());
        $this->withHeader('X-CSRF-TOKEN', 'bootstrap-csrf')->post('/api/appointments', $payload)->assertCreated();
        $this->post('/api/appointments', array_replace($payload, ['appointment_date' => '2026-11-20T12:00']))->assertCreated();
        $this->get('/api/appointments?fecha=2026-11-20')->assertOk()->assertJsonCount(2);
        $this->get('/agenda?fecha=2026-11-20')->assertOk()->assertViewHas('fechaSeleccionada', '2026-11-20');
        $this->get('/api/appointments?fecha=2026-02-30')->assertUnprocessable();
        $this->assertSame(2, $service->citas()->count());
        $this->assertSame(2, $stylist->citas()->count());
    }
}

class EnforcedBootstrapCsrf extends PreventRequestForgery
{
    protected function runningUnitTests() { return false; }
}
