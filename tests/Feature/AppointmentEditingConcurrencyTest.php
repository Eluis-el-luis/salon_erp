<?php

namespace Tests\Feature;

use App\Models\{Cita,Cliente,Rol,Servicio,Usuario};
use App\Services\AgendaService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithAppointmentWorkers;
use Tests\TestCase;

/** Fixtures confirmados solo en pruebas; cada request usa un proceso/PDO diferente. */
class AppointmentEditingConcurrencyTest extends TestCase
{
    use InteractsWithAppointmentWorkers;

    public function test_two_edits_of_same_appointment_reject_a_stale_form(): void { $this->race('same-style'); }
    public function test_assignment_change_rejects_an_unlocked_new_current_stylist(): void { $this->race('changed-style'); }
    public function test_reprogramming_and_new_booking_for_new_stylist_cannot_overlap(): void { $this->race('reserve'); }

    private function race(string $mode): void
    {
        $this->assertSame(0,DB::transactionLevel());
        $tag=bin2hex(random_bytes(6));$role=Rol::create(['name'=>'H15 concurrency '.$tag]);
        $users=[];$client=null;$service=null;
        try {
            for($i=0;$i<3;$i++)$users[]=Usuario::create(['name'=>'H15 race','email'=>$tag.$i.'@example.test','password'=>'fixture-password','role'=>'estilista','role_id'=>$role->id,'is_active'=>true]);
            $client=Cliente::create(['name'=>'H15 race '.$tag]);
            $service=Servicio::create(['name'=>'H15 race '.$tag,'price'=>100,'duration'=>45,'is_active'=>true]);
            $base=['client_id'=>$client->id,'service_id'=>$service->id,'stylists'=>[$users[0]->id],'appointment_date'=>'2026-12-01T10:00','notes'=>'Original'];
            $appointment=app(AgendaService::class)->reservar($base);
            $token=app(AgendaService::class)->paraEditar($appointment->id)['edit_token'];
            $newStyle=$mode==='same-style'?$users[0]->id:$users[1]->id;
            $payloadA=array_replace($base,['stylists'=>[$newStyle],'appointment_date'=>'2026-12-01T11:00','notes'=>'Edit A','edit_token'=>$token]);
            $a=$this->start($payloadA,$users[0]->id,true,$appointment->id);$readyA=$this->event($a,'ready');$this->event($a,'locked');
            if($mode==='reserve') {
                $payloadB=array_replace($base,['stylists'=>[$newStyle],'appointment_date'=>'2026-12-01T11:00']);
                $b=$this->start($payloadB,$users[1]->id,false);
            } else {
                $payloadB=array_replace($base,['stylists'=>[$mode==='same-style'?$users[0]->id:$users[2]->id],'appointment_date'=>'2026-12-01T12:00','notes'=>'Edit B','edit_token'=>$token]);
                $b=$this->start($payloadB,$users[1]->id,false,$appointment->id);
            }
            $readyB=$this->event($b,'ready');$this->event($b,'attempt');
            $this->assertNotSame($readyA['connection'],$readyB['connection']);
            $this->waitForUserLock($readyB['connection']);$this->release($a);
            $ra=$this->event($a,'result');$rb=$this->event($b,'result');
            $this->assertSame(200,$ra['status']);$this->assertSame($appointment->id,$ra['id']);
            $this->assertSame($mode==='reserve'?422:409,$rb['status']);$this->assertTrue($rb['conflict']);
            $this->assertSame(1,Cita::where('client_id',$client->id)->count());
            $persisted=$appointment->fresh();
            $this->assertSame('2026-12-01 11:00:00',$persisted->appointment_date);
            $this->assertSame('Edit A',$persisted->notes);$this->assertSame('pendiente',$persisted->status);
            $this->assertSame([$newStyle],$persisted->estilistas()->pluck('users.id')->all());
            $this->assertSame(1,DB::table('appointment_user')->where('appointment_id',$appointment->id)->count());
            $this->finish();$this->assertWorkersDisconnected([$readyA['connection'],$readyB['connection']]);
        } finally {
            $this->stopAppointmentWorkers();
            if($client){Cita::where('client_id',$client->id)->delete();$client->forceDelete();$this->assertDatabaseMissing('clients',['id'=>$client->id]);}
            if($service)$service->delete();foreach($users as $user)$user->delete();$role->delete();
        }
    }
}
