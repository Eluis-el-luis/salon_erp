<?php

namespace Tests\Feature;

use App\Exceptions\ContabilidadException;
use App\Models\{Cita, Cliente, Rol, Servicio, SesionCaja, Usuario, Venta};
use App\Services\AprobacionService;
use Database\Seeders\{ContabilidadBaseSeeder, CuentasComplementariasSeeder, RoleSeeder};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Solo MySQL aislado; fixtures, sin servicios externos ni red. */
class AppointmentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Servicio $service;
    private Usuario $owner;
    private array $styles;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->owner = $this->user('alvaro', 'owner');
        $this->owner->update(['pin_seguridad' => Hash::make('8462')]); // PIN público de fixture.
        $this->styles = [$this->user('estilista','one'), $this->user('estilista','two')];
        $this->service = Servicio::create(['name'=>'Servicio H16','price'=>200,'duration'=>45,'cleanup_minutes'=>30,'is_active'=>true]);
        $client = Cliente::create(['name'=>'Cliente H16']);
        $this->payload = ['client_id'=>$client->id,'service_id'=>$this->service->id,'stylists'=>[$this->styles[0]->id],'appointment_date'=>'2026-11-20T10:00'];
        $this->actingAs($this->owner);
        $this->app->bind(PreventRequestForgery::class, EnforcedH16Csrf::class);
        $this->withSession(['_token'=>'h16-fixture']);
        $this->withHeaders(['Accept'=>'application/json','X-CSRF-TOKEN'=>'h16-fixture']);
    }

    private function user(string $role, string $suffix): Usuario
    {
        return Usuario::create(['name'=>'H16 '.$suffix,'email'=>$suffix.'@h16.example.test','password'=>'fixture-password','role'=>$role,'role_id'=>Rol::firstOrFail()->id,'is_active'=>true]);
    }
    private function reserve(array $extra=[]): int
    {
        return $this->post('/api/appointments',array_replace($this->payload,$extra))->assertCreated()->json('appointment.id');
    }
    private function sale(int $id,string $status): Venta
    {
        return Venta::create(['cashier_id'=>$this->owner->id,'client_id'=>$this->payload['client_id'],'appointment_id'=>$id,'subtotal'=>200,'discount'=>0,'total'=>200,'status'=>$status,'currency'=>'nio','exchange_rate'=>1,'payment_method'=>'efectivo']);
    }
    private function cashAndAccounts(): void
    {
        $this->seed(ContabilidadBaseSeeder::class); $this->seed(CuentasComplementariasSeeder::class);
        SesionCaja::create(['user_id'=>$this->owner->id,'monto_apertura'=>0,'estado'=>'abierta','fecha_apertura'=>now()]);
    }
    private function financialSnapshot(): array
    {
        $result=[];
        foreach(['sales','sale_details','pagos','asientos_contables','detalle_asientos','comisiones_generadas','stock_ledger_entries','lotes','saldos_moneda'] as $t) {
            $result[$t]=DB::table($t)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
        }
        return $result;
    }

    public function test_separate_and_consecutive_appointments_and_own_completion(): void
    {
        $id=$this->reserve();
        $this->reserve(['appointment_date'=>'2026-11-20T10:45']);
        $this->reserve(['appointment_date'=>'2026-11-20T12:00']);
        $this->put('/api/appointments/'.$id,['status'=>'completada'])->assertOk();
        $this->put('/api/appointments/'.$id,['status'=>'completada'])->assertOk();
        $this->assertSame(3,Cita::count());
        $this->post('/api/appointments',$this->payload)->assertUnprocessable()->assertJsonValidationErrors('availability');
    }
    public function test_partial_equal_and_containing_overlaps_are_rejected(): void
    {
        $this->reserve();
        foreach(['2026-11-20T09:45','2026-11-20T10:00','2026-11-20T10:30'] as $time) {
            $this->post('/api/appointments',array_replace($this->payload,['appointment_date'=>$time]))->assertUnprocessable()->assertJsonValidationErrors('availability');
        }
        $this->service->update(['duration'=>120]);
        $this->post('/api/appointments',array_replace($this->payload,['appointment_date'=>'2026-11-20T09:30']))->assertUnprocessable();
        $this->assertSame(1,Cita::count());
    }
    public function test_different_stylists_and_all_members_of_a_group_are_checked(): void
    {
        $this->reserve();
        $this->post('/api/appointments',array_replace($this->payload,['stylists'=>[$this->styles[1]->id,$this->styles[0]->id]]))->assertUnprocessable();
        $this->reserve(['stylists'=>[$this->styles[1]->id]]);
        $id=$this->reserve(['appointment_date'=>'2026-11-20T11:00','stylists'=>[$this->styles[1]->id,$this->styles[0]->id]]);
        $this->assertSame(2,Cita::findOrFail($id)->estilistas()->count());
        $this->assertSame(3,Cita::count());
        $this->assertSame(4,DB::table('appointment_user')->count());
    }
    public function test_midnight_crossing_and_consecutive_next_day(): void
    {
        $this->reserve(['appointment_date'=>'2026-11-20T23:45']);
        $this->post('/api/appointments',array_replace($this->payload,['appointment_date'=>'2026-11-21T00:15']))->assertUnprocessable();
        $this->reserve(['appointment_date'=>'2026-11-21T00:30']);
        $this->reserve(['appointment_date'=>'2026-11-21T23:45']);
        $this->assertSame(3,Cita::count());
    }
    public function test_duration_is_from_service_frozen_and_without_cleanup(): void
    {
        $this->service->update(['duration'=>60]);
        $id=$this->reserve(['duration_minutes'=>1]);
        $this->assertSame(60,(int)Cita::findOrFail($id)->duration_minutes);
        $this->service->update(['duration'=>90]);
        $this->reserve(['appointment_date'=>'2026-11-20T11:00']);
        $this->assertSame(60,(int)Cita::findOrFail($id)->duration_minutes);
    }
    public function test_invalid_service_durations_and_browser_values_are_rejected(): void
    {
        foreach([0,-1] as $duration) {
            $this->service->update(['duration'=>$duration]);
            $this->post('/api/appointments',$this->payload)->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        }
        $this->service->update(['duration'=>45]);
        foreach([null,0,-1,'invalid',1.5,[]] as $duration) $this->post('/api/appointments',array_replace($this->payload,['duration_minutes'=>$duration]))->assertUnprocessable();
        $this->assertSame(0,Cita::count());
    }
    public function test_empty_duplicate_and_missing_stylists_are_rejected(): void
    {
        foreach([[],[$this->styles[0]->id,$this->styles[0]->id],[99999999]] as $ids) $this->post('/api/appointments',array_replace($this->payload,['stylists'=>$ids]))->assertUnprocessable();
        $this->assertSame(0,Cita::count());
    }
    public static function releasingStates(): array { return [['cancelada'],['no_asistio']]; }

    #[DataProvider('releasingStates')]
    public function test_release_and_reactivation_recheck_and_keep_snapshot(string $state): void
    {
        $id=$this->reserve();
        $this->put('/api/appointments/'.$id,['status'=>$state])->assertOk();
        $other=$this->reserve();
        $this->put('/api/appointments/'.$id,['status'=>'pendiente'])->assertUnprocessable()->assertJsonValidationErrors('availability');
        $this->assertSame($state,Cita::findOrFail($id)->status);
        $this->put('/api/appointments/'.$other,['status'=>'cancelada'])->assertOk();
        $this->service->update(['duration'=>120]);
        $this->put('/api/appointments/'.$id,['status'=>'pendiente'])->assertOk();
        $this->assertSame(45,(int)Cita::findOrFail($id)->duration_minutes);
    }
    public static function financialStates(): array
    {
        $result=[];
        foreach(['pagada','emitida','pendiente_aprobacion','borrador'] as $sale) foreach(['cancelada','no_asistio'] as $state) $result[$sale.'-'.$state]=[$sale,$state];
        return $result;
    }
    #[DataProvider('financialStates')]
    public function test_non_annulled_sale_prevents_both_releasing_transitions(string $saleStatus,string $state): void
    {
        $id=$this->reserve(); $this->sale($id,$saleStatus); $before=$this->financialSnapshot();
        $this->put('/api/appointments/'.$id,['status'=>$state])->assertUnprocessable()->assertJsonValidationErrors('financial_link');
        $this->assertSame('pendiente',Cita::findOrFail($id)->status);
        $this->assertSame($before,$this->financialSnapshot());
    }
    #[DataProvider('releasingStates')]
    public function test_annulled_sale_does_not_prevent_release(string $state): void
    {
        $id=$this->reserve(); $sale=$this->sale($id,'anulada'); $before=$this->financialSnapshot();
        $this->put('/api/appointments/'.$id,['status'=>$state])->assertOk();
        $this->reserve();
        $this->assertSame($before,$this->financialSnapshot()); $this->assertSame('anulada',$sale->fresh()->status);
    }
    #[DataProvider('releasingStates')]
    public function test_direct_billing_and_stale_pos_cannot_reactivate_released_appointment(string $state): void
    {
        $this->cashAndAccounts();
        $id=$this->reserve();
        $cart=['type'=>'servicio','id'=>$this->service->id,'quantity'=>1,'precio_c'=>200,'stylist_id'=>$this->styles[0]->id,'appointment_id'=>$id];
        $this->put('/api/appointments/'.$id,['status'=>$state])->assertOk(); $this->reserve();
        $before=$this->financialSnapshot();
        $this->post('/sales/bill-appointment/'.$id,['currency'=>'nio'])->assertUnprocessable()->assertJsonPath('error',fn($text)=>str_contains($text,'Reactívela'));
        $this->post('/sales',['cart'=>[$cart],'client_id'=>$this->payload['client_id']])->assertUnprocessable();
        $this->assertSame($before,$this->financialSnapshot());
        $this->assertSame($state,Cita::findOrFail($id)->status);
    }
    #[DataProvider('releasingStates')]
    public function test_approval_rejects_incompatible_legacy_state_without_partial_effects(string $state): void
    {
        $this->cashAndAccounts(); $id=$this->reserve();
        $this->post('/sales/bill-appointment/'.$id,['requiere_aprobacion'=>true])->assertCreated();
        $sale=Venta::sole();
        $this->put('/api/appointments/'.$id,['status'=>$state])->assertUnprocessable();
        // Incoherencia heredada/controlada: el API nuevo no permite crearla.
        DB::table('appointments')->where('id',$id)->update(['status'=>$state]);
        $before=$this->financialSnapshot();
        try { app(AprobacionService::class)->aprobarVenta($sale->id,$this->owner->id); $this->fail('Expected rejection'); }
        catch(ContabilidadException $e) { $this->assertStringContainsString('Reactívela',$e->getMessage()); }
        $this->assertSame($before,$this->financialSnapshot());
        $this->assertSame('pendiente_aprobacion',$sale->fresh()->status);
    }
    public function test_approval_accepts_completed_appointment_and_preserves_existing_accounting(): void
    {
        $this->cashAndAccounts(); $id=$this->reserve();
        $this->post('/sales/bill-appointment/'.$id,['requiere_aprobacion'=>true])->assertCreated();
        $this->put('/api/appointments/'.$id,['status'=>'completada'])->assertOk();
        $sale=app(AprobacionService::class)->aprobarVenta(Venta::sole()->id,$this->owner->id);
        $this->assertSame('pagada',$sale->status); $this->assertSame('completada',Cita::findOrFail($id)->status);
        $this->assertGreaterThan(0,DB::table('asientos_contables')->count());
    }
    public function test_late_financial_failure_rolls_back_sale_asiento_and_completion(): void
    {
        $this->cashAndAccounts(); $id=$this->reserve(); $before=$this->financialSnapshot(); $reached=false;
        DB::listen(function(QueryExecuted $query) use(&$reached) {
            if(str_starts_with(strtolower($query->sql),'insert into `asientos_contables`')) { $reached=true; throw new ContabilidadException('Fallo financiero simulado H16'); }
        });
        $this->post('/sales/bill-appointment/'.$id,[])->assertUnprocessable()->assertJsonPath('error','Fallo financiero simulado H16');
        $this->assertTrue($reached); $this->assertSame($before,$this->financialSnapshot());
        $this->assertSame('pendiente',Cita::findOrFail($id)->status);
    }
    public function test_annulment_keeps_refactoring_state_and_releases_financial_block_only(): void
    {
        $this->cashAndAccounts(); $id=$this->reserve();
        $this->post('/sales/bill-appointment/'.$id,[])->assertOk(); $sale=Venta::sole();
        $this->post('/ventas/'.$sale->id.'/anular',['motivo'=>'Reversión de fixture H16','pin_supervisor'=>'8462'])->assertRedirect();
        $this->assertSame('anulada',$sale->fresh()->status); $this->assertSame('pendiente',Cita::findOrFail($id)->status);
        $this->post('/api/appointments',$this->payload)->assertUnprocessable();
        $this->put('/api/appointments/'.$id,['status'=>'cancelada'])->assertOk(); $this->reserve();
    }
}

class EnforcedH16Csrf extends PreventRequestForgery
{
    protected function runningUnitTests() { return false; }
}
