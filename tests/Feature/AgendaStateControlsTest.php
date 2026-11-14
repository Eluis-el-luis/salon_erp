<?php

namespace Tests\Feature;

use App\Models\{Cita,Cliente,Rol,Servicio,Usuario};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Render de Blade final, sin migraciones/DDL; todos los fixtures hacen rollback. */
class AgendaStateControlsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_state_controls_show_history_and_only_pending_can_open_pos(): void
    {
        $role=Rol::firstOrFail();
        $user=Usuario::create(['name'=>'H16 UI','email'=>'ui@h16.example.test','password'=>'fixture-password','role'=>'estilista','role_id'=>$role->id,'is_active'=>true]);
        $client=Cliente::create(['name'=>'H16 UI']);
        $service=Servicio::create(['name'=>'H16 UI','price'=>100,'duration'=>45,'is_active'=>true]);
        $ids=[];
        foreach(['pendiente','completada','cancelada','no_asistio'] as $i=>$state) {
            $cita=Cita::create(['client_id'=>$client->id,'service_id'=>$service->id,'appointment_date'=>'2026-11-20 '.(10+$i).':00:00','duration_minutes'=>45,'status'=>$state]);
            $cita->estilistas()->sync([$user->id]);$ids[$state]=$cita->id;
        }
        $response=$this->actingAs($user)->get('/agenda?fecha=2026-11-20')->assertOk()->assertSee('No asistió')->assertSee('Cancelada');
        foreach(['cancelada','no_asistio'] as $state) $response->assertSee("changeAppointmentStatus({$ids[$state]}, 'pendiente')",false);
        $response->assertSee("changeAppointmentStatus({$ids['pendiente']}, 'cancelada')",false)
            ->assertSee("changeAppointmentStatus({$ids['pendiente']}, 'no_asistio')",false);
        preg_match_all('/href="[^"]*appointment_id=(\d+)&/',$response->getContent(),$links);
        $this->assertSame([(string)$ids['pendiente']],$links[1]);
        $response->assertSee('role="alert"',false);
    }
}
