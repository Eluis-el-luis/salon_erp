<?php

namespace Tests\Feature;

use App\Models\{Cita, Cliente, Rol, Servicio, Usuario, Venta};
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** H15: fixtures SQL locales, sin servicios externos. */
class AppointmentEditingTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $actor;
    private array $styles;
    private Cliente $client;
    private Servicio $service;
    private Cita $appointment;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RoleSeeder::class);
        $this->styles=[];
        foreach(['actor','one','two','three'] as $i=>$name) {
            $u=Usuario::create(['name'=>'H15 '.$name,'email'=>$name.'@h15.example.test','password'=>'fixture-password','role'=>$i?'estilista':'alvaro','role_id'=>Rol::firstOrFail()->id,'is_active'=>true]);
            if(!$i)$this->actor=$u;else $this->styles[]=$u;
        }
        $this->client=Cliente::create(['name'=>'Cliente H15']);
        $this->service=Servicio::create(['name'=>'Servicio H15','price'=>100,'duration'=>45,'cleanup_minutes'=>30,'is_active'=>true]);
        $this->actingAs($this->actor);
        $this->app->bind(PreventRequestForgery::class,EnforcedH15Csrf::class);
        $this->withSession(['_token'=>'h15-fixture']);
        $this->withHeaders(['Accept'=>'application/json','X-CSRF-TOKEN'=>'h15-fixture']);
        $this->appointment=$this->reserve();
    }
    private function reserve(array $extra=[]): Cita
    {
        $payload=array_replace(['client_id'=>$this->client->id,'service_id'=>$this->service->id,'stylists'=>[$this->styles[0]->id],'appointment_date'=>'2026-11-20T10:00','notes'=>'Original'],$extra);
        $id=$this->post('/api/appointments',$payload)->assertCreated()->json('appointment.id');
        return Cita::findOrFail($id);
    }
    private function token(?int $id=null): string
    {
        return $this->get('/api/appointments/'.($id??$this->appointment->id))->assertOk()->json('edit_token');
    }
    private function edit(array $data,?string $token=null)
    {
        return $this->put('/api/appointments/'.$this->appointment->id.'/details',array_replace(['edit_token'=>$token??$this->token()],$data));
    }
    private function sale(string $status): Venta
    {
        return Venta::create(['cashier_id'=>$this->actor->id,'client_id'=>$this->client->id,'appointment_id'=>$this->appointment->id,'subtotal'=>100,'discount'=>0,'total'=>100,'status'=>$status]);
    }
    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
    }

    public function test_notes_do_not_change_status_interval_or_duration(): void
    {
        $before=$this->appointment->getAttributes();
        $this->edit(['notes'=>'Nueva nota'])->assertOk()->assertJsonPath('appointment.status','pendiente');
        $after=$this->appointment->fresh();
        foreach(['status','appointment_date','duration_minutes','service_id','client_id'] as $field) $this->assertSame($before[$field],$after->getAttributes()[$field]);
        $this->assertSame('Nueva nota',$after->notes);
        $this->edit(['notes'=>null])->assertOk();$this->assertNull($this->appointment->fresh()->notes);
    }
    public function test_state_and_data_endpoints_do_not_infer_or_mix_operations(): void
    {
        $url='/api/appointments/'.$this->appointment->id;
        $this->put($url,['notes'=>'No completar'])->assertUnprocessable();
        $this->put($url,[])->assertUnprocessable();
        $this->put($url,['status'=>'completada','notes'=>'Mezcla'])->assertUnprocessable();
        $this->edit(['status'=>'completada','notes'=>'Mezcla'])->assertUnprocessable();
        $this->edit(['duration_minutes'=>1])->assertUnprocessable();
        $this->edit([])->assertUnprocessable();
        $this->assertSame('pendiente',$this->appointment->fresh()->status);
        $this->assertSame('Original',$this->appointment->fresh()->notes);
    }
    public function test_reprogramming_and_client_change_return_saved_confirmation(): void
    {
        $other=Cliente::create(['name'=>'Otro H15']);
        $this->edit(['client_id'=>$other->id,'appointment_date'=>'2026-11-21T12:34:15'])->assertOk()
            ->assertJsonPath('appointment.id',$this->appointment->id)
            ->assertJsonPath('appointment.appointment_date','2026-11-21 12:34:15');
        $this->assertSame($other->id,$this->appointment->fresh()->client_id);
        $this->reserve(); // El horario anterior queda libre.
    }
    public function test_conflict_rejects_all_changes_including_notes(): void
    {
        $this->reserve(['appointment_date'=>'2026-11-20T12:00']);
        $before=$this->rows('appointments');$pivot=$this->rows('appointment_user');
        $this->edit(['appointment_date'=>'2026-11-20T11:30','notes'=>'No persistir'])->assertUnprocessable()->assertJsonValidationErrors('availability');
        $this->assertSame($before,$this->rows('appointments'));$this->assertSame($pivot,$this->rows('appointment_user'));
    }
    public function test_old_and_new_stylist_availability_and_groups(): void
    {
        $this->edit(['stylists'=>[$this->styles[1]->id]])->assertOk();
        $this->reserve();
        $this->edit(['stylists'=>[$this->styles[0]->id,$this->styles[1]->id]])->assertUnprocessable();
        $this->edit(['stylists'=>[$this->styles[1]->id,$this->styles[2]->id]])->assertOk();
        $this->assertSame([$this->styles[1]->id,$this->styles[2]->id],$this->appointment->fresh()->estilistas()->orderBy('users.id')->pluck('users.id')->all());
        foreach([1,2] as $i) $this->post('/api/appointments',['client_id'=>$this->client->id,'service_id'=>$this->service->id,'stylists'=>[$this->styles[$i]->id],'appointment_date'=>'2026-11-20T10:30'])->assertUnprocessable();
    }
    public function test_service_change_refreshes_duration_but_catalog_edits_do_not(): void
    {
        $this->service->update(['duration'=>120]);
        $this->edit(['appointment_date'=>'2026-11-20T11:00','service_id'=>$this->service->id])->assertOk();
        $this->assertSame(45,(int)$this->appointment->fresh()->duration_minutes);
        $other=Servicio::create(['name'=>'Otro H15','price'=>200,'duration'=>75,'cleanup_minutes'=>40,'is_active'=>true]);
        $this->edit(['service_id'=>$other->id])->assertOk()->assertJsonPath('appointment.duration_minutes',75);
        $other->update(['duration'=>0]);
        $this->edit(['notes'=>'Conservar duración histórica'])->assertOk();
        $this->assertSame(75,(int)$this->appointment->fresh()->duration_minutes);
    }
    public function test_invalid_new_service_duration_is_rejected_without_mutation(): void
    {
        $other=Servicio::create(['name'=>'Inválido H15','price'=>100,'duration'=>0,'is_active'=>true]);
        $before=$this->rows('appointments');
        $this->edit(['service_id'=>$other->id])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        $this->assertSame($before,$this->rows('appointments'));
    }
    public function test_same_interval_and_group_are_not_a_self_conflict(): void
    {
        $this->appointment->estilistas()->sync([$this->styles[0]->id,$this->styles[1]->id]);
        $pivot=$this->rows('appointment_user');
        $this->edit(['client_id'=>$this->client->id,'service_id'=>$this->service->id,'stylists'=>[$this->styles[1]->id,$this->styles[0]->id],'appointment_date'=>'2026-11-20T10:00:00','notes'=>'Mismo intervalo'])->assertOk();
        $this->assertSame($pivot,$this->rows('appointment_user'));$this->assertSame(1,Cita::count());
    }
    public static function protectedStates(): array { return [['completada'],['cancelada'],['no_asistio']]; }
    #[DataProvider('protectedStates')]
    public function test_protected_states_allow_notes_only_and_preserve_state(string $state): void
    {
        $this->put('/api/appointments/'.$this->appointment->id,['status'=>$state])->assertOk();
        $this->get('/api/appointments/'.$this->appointment->id)->assertOk()->assertJsonPath('can_edit_commercial',false);
        $this->edit(['appointment_date'=>'2026-11-21T12:00'])->assertUnprocessable()->assertJsonValidationErrors('editing');
        $this->edit(['service_id'=>Servicio::create(['name'=>'Protegido H15','price'=>100,'duration'=>30])->id])->assertUnprocessable();
        $this->edit(['stylists'=>[$this->styles[1]->id]])->assertUnprocessable();
        $this->edit(['client_id'=>Cliente::create(['name'=>'Protegido H15'])->id])->assertUnprocessable();
        $this->edit(['notes'=>'Nota histórica'])->assertOk();
        $this->assertSame($state,$this->appointment->fresh()->status);
        if($state!=='completada') {
            $this->put('/api/appointments/'.$this->appointment->id,['status'=>'pendiente'])->assertOk();
            $this->edit(['appointment_date'=>'2026-11-21T12:00'])->assertOk();
        }
    }
    public static function saleStates(): array { return [['pagada'],['emitida'],['pendiente_aprobacion'],['borrador']]; }
    #[DataProvider('saleStates')]
    public function test_non_annulled_document_protects_commercial_data_but_not_notes(string $state): void
    {
        $this->sale($state);$before=$this->rows('sales');
        $this->get('/api/appointments/'.$this->appointment->id)->assertJsonPath('can_edit_commercial',false);
        $this->edit(['appointment_date'=>'2026-11-21T10:00'])->assertUnprocessable();
        $this->edit(['notes'=>'Nota operativa'])->assertOk();
        $this->assertSame($before,$this->rows('sales'));$this->assertSame('pendiente',$this->appointment->fresh()->status);
    }
    public function test_annulled_document_does_not_block_editing_and_is_not_changed(): void
    {
        $this->sale('anulada');$before=$this->rows('sales');
        $this->edit(['appointment_date'=>'2026-11-21T10:00'])->assertOk();
        $this->assertSame($before,$this->rows('sales'));
    }
    public function test_cross_midnight_conflict_and_consecutive_boundary(): void
    {
        $this->reserve(['appointment_date'=>'2026-11-21T00:15']);
        $this->edit(['appointment_date'=>'2026-11-20T23:50'])->assertUnprocessable();
        $this->edit(['appointment_date'=>'2026-11-20T23:30'])->assertOk();
        $this->assertSame('2026-11-20 23:30:00',$this->appointment->fresh()->appointment_date);
    }
    public function test_old_form_is_rejected_even_when_timestamp_precision_is_one_second(): void
    {
        $timestamp=$this->appointment->getRawOriginal('updated_at');
        $this->travelTo(\Carbon\Carbon::parse($timestamp,config('app.timezone')));
        try {
            $token=$this->token();
            $this->edit(['notes'=>'Primero'],$token)->assertOk();
            $this->assertSame($timestamp,$this->appointment->fresh()->getRawOriginal('updated_at'));
            $this->edit(['notes'=>'Sobrescritura obsoleta'],$token)->assertStatus(409)->assertJsonValidationErrors('edit_conflict');
            $this->assertSame('Primero',$this->appointment->fresh()->notes);
            $this->put('/api/appointments/'.$this->appointment->id.'/details',['notes'=>'Sin versión'])->assertUnprocessable();
        } finally {$this->travelBack();}
    }
    public function test_association_failure_rolls_back_data_and_original_pivot(): void
    {
        $token=$this->token();$before=$this->rows('appointments');$pivot=$this->rows('appointment_user');$reached=false;
        DB::listen(function(QueryExecuted $q) use(&$reached){if(str_starts_with(strtolower($q->sql),'insert into `appointment_user`')){$reached=true;throw new \RuntimeException('Fallo H15 de asociación');}});
        $this->withoutExceptionHandling();
        try {$this->edit(['appointment_date'=>'2026-11-21T10:00','stylists'=>[$this->styles[1]->id],'notes'=>'No persistir'],$token);$this->fail('Expected rollback');}
        catch(\RuntimeException $e){$this->assertSame('Fallo H15 de asociación',$e->getMessage());}
        $this->assertTrue($reached);$this->assertSame($before,$this->rows('appointments'));$this->assertSame($pivot,$this->rows('appointment_user'));
    }
    public function test_editing_enforces_real_csrf_auth_and_restricted_preload(): void
    {
        $token=$this->token();$url='/api/appointments/'.$this->appointment->id.'/details';
        $this->withHeader('X-CSRF-TOKEN','')->put($url,['edit_token'=>$token,'notes'=>'Sin token'])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN','incorrecto')->put($url,['edit_token'=>$token,'notes'=>'Incorrecto'])->assertStatus(419);
        $this->app['auth']->forgetGuards();
        $this->get('/api/appointments/'.$this->appointment->id)->assertUnauthorized();
        $this->withHeader('X-CSRF-TOKEN','h15-fixture')->put($url,['edit_token'=>$token,'notes'=>'Intruso'])->assertUnauthorized();
        $this->actingAs($this->actor)->get('/api/appointments/'.$this->appointment->id)->assertOk()
            ->assertJsonPath('appointment.stylists',[$this->styles[0]->id])->assertJsonMissingPath('appointment.estilistas')->assertJsonMissingPath('sales');
        $this->edit(['notes'=>'Autorizado'],$token)->assertOk();
    }
    public function test_modal_has_editing_and_notes_controls_and_preloads_inactive_choices(): void
    {
        $this->service->update(['is_active'=>false]);$this->styles[0]->update(['is_active'=>false]);
        $this->get('/api/appointments/'.$this->appointment->id)->assertOk()
            ->assertJsonPath('choices.services.0.id',$this->service->id)
            ->assertJsonPath('choices.stylists.0.id',$this->styles[0]->id);
        $this->get('/agenda?fecha=2026-11-20')->assertOk()->assertSee('Guardar cambios')->assertSee('appointment-notes')
            ->assertSee('editAppointment('.$this->appointment->id.')',false)->assertSee('step="1"',false);
    }
}

class EnforcedH15Csrf extends PreventRequestForgery
{
    protected function runningUnitTests() { return false; }
}
