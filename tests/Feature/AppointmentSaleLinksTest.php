<?php

namespace Tests\Feature;

use App\Models\{Cita,Cliente,Rol,Servicio,SesionCaja,Usuario,Venta};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Guardias de vínculo singular de sales; transaccional, sin DDL ni servicios externos. */
class AppointmentSaleLinksTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $owner;
    private Cliente $client;
    private array $appointments;
    private array $line;

    protected function setUp(): void
    {
        parent::setUp();
        $role=Rol::firstOrFail();
        $this->owner=Usuario::create(['name'=>'H16 links','email'=>'links@h16.example.test','password'=>'fixture-password','role'=>'alvaro','role_id'=>$role->id,'is_active'=>true]);
        $this->client=Cliente::create(['name'=>'H16 links']);
        $service=Servicio::create(['name'=>'H16 links','price'=>100,'duration'=>45,'is_active'=>true]);
        $this->appointments=[];
        foreach([10,11] as $hour) {
            $cita=Cita::create(['client_id'=>$this->client->id,'service_id'=>$service->id,'appointment_date'=>'2026-11-20 '.$hour.':00:00','duration_minutes'=>45,'status'=>'pendiente']);
            $cita->estilistas()->sync([$this->owner->id]);$this->appointments[]=$cita;
        }
        $this->line=['type'=>'servicio','id'=>$service->id,'quantity'=>1,'precio_c'=>100,'stylist_id'=>$this->owner->id,'appointment_id'=>$this->appointments[0]->id];
        SesionCaja::create(['user_id'=>$this->owner->id,'monto_apertura'=>0,'estado'=>'abierta','fecha_apertura'=>now()]);
        $this->actingAs($this->owner)->withHeader('Accept','application/json');
    }

    public function test_distinct_appointments_are_rejected_before_sales_or_drafts_are_created(): void
    {
        $other=array_replace($this->line,['appointment_id'=>$this->appointments[1]->id]);
        foreach(['pagar','cotizar'] as $action) {
            $this->post('/sales',['cart'=>[$this->line,$other],'action'=>$action])->assertUnprocessable()->assertJsonPath('error','Una venta solo puede vincularse a una cita. Facture las citas por separado.');
        }
        foreach(['sales','sale_details','pagos','asientos_contables'] as $table) $this->assertSame(0,DB::table($table)->count());
        $this->assertSame(2,Cita::where('status','pendiente')->count());
    }
    public function test_draft_cannot_silently_keep_one_appointment_and_complete_another(): void
    {
        $draft=Venta::create(['cashier_id'=>$this->owner->id,'client_id'=>$this->client->id,'appointment_id'=>$this->appointments[0]->id,'subtotal'=>100,'discount'=>0,'total'=>100,'status'=>'borrador']);
        // Comparar dos estados hidratados: MySQL normaliza decimales y defaults.
        $before=$draft->fresh()->getAttributes();
        $other=array_replace($this->line,['appointment_id'=>$this->appointments[1]->id]);
        $this->post('/sales',['cart'=>[$other],'draft_id'=>$draft->id])->assertUnprocessable();
        $this->assertSame($before,$draft->fresh()->getAttributes());
        $this->assertSame(0,DB::table('sale_details')->count());
        $this->assertSame(2,Cita::where('status','pendiente')->count());
    }
    public function test_multiple_lines_for_one_appointment_keep_the_existing_retained_sale_flow(): void
    {
        $this->post('/sales',['cart'=>[$this->line,$this->line],'client_id'=>$this->client->id,'requiere_aprobacion'=>true])->assertCreated();
        $sale=Venta::sole();
        $this->assertSame($this->appointments[0]->id,$sale->appointment_id);
        $this->assertSame('pendiente_aprobacion',$sale->status);
        $this->assertSame(2,$sale->detalles()->count());
        $this->assertSame(0,DB::table('pagos')->count());
        $this->assertSame(0,DB::table('asientos_contables')->count());
        $this->assertSame('pendiente',$this->appointments[0]->fresh()->status);
    }
}
