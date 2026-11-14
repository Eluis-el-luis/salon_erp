<?php

namespace Tests\Feature;

use App\Models\{Cita,Cliente,Rol,Servicio,Usuario};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\InteractsWithAppointmentWorkers;

/** Sin transacción de fixtures padre: los hijos necesitan datos confirmados. */
class AppointmentConcurrencyTest extends TestCase
{
    use InteractsWithAppointmentWorkers;

    public function test_real_mysql_requests_serialize_and_recheck_a_previous_snapshot(): void
    {
        $this->runRace(true);
    }
    public function test_different_stylists_do_not_share_the_user_mutex(): void
    {
        $this->runRace(false);
    }
    private function runRace(bool $same): void
    {
        $this->assertSame(0,DB::transactionLevel());
        $tag=bin2hex(random_bytes(6));
        $role=Rol::create(['name'=>'H16 concurrency '.$tag]);
        $users=[]; $client=null; $service=null;
        try {
            foreach(['a','b'] as $suffix) $users[]=Usuario::create(['name'=>'H16 race','email'=>$tag.$suffix.'@example.test','password'=>'fixture-password','role'=>'estilista','role_id'=>$role->id,'is_active'=>true]);
            $client=Cliente::create(['name'=>'H16 race '.$tag]);
            $service=Servicio::create(['name'=>'H16 race '.$tag,'price'=>100,'duration'=>45,'is_active'=>true]);
            $payload=['client_id'=>$client->id,'service_id'=>$service->id,'stylists'=>[$users[0]->id],'appointment_date'=>'2026-12-01T10:00'];
            $a=$this->start($payload,$users[0]->id,true);$readyA=$this->event($a,'ready');$this->event($a,'locked');
            if(!$same) $payload['stylists']=[$users[1]->id];
            $b=$this->start($payload,$users[1]->id,false);$readyB=$this->event($b,'ready');$this->event($b,'attempt');
            $this->assertNotSame($readyA['connection'],$readyB['connection']);
            if($same) {
                $this->waitForUserLock($readyB['connection']);
            } else {
                // B debe poder confirmar mientras A retiene el mutex de OTRO estilista.
                $this->assertSame(201,$this->event($b,'result')['status']);
            }
            $this->release($a);
            $ra=$this->event($a,'result');$rb=$this->event($b,'result');
            $this->assertSame(201,$ra['status']);$this->assertSame($same?422:201,$rb['status']);
            if($same) $this->assertTrue($rb['conflict']);
            $rows=Cita::where('client_id',$client->id)->get();
            $this->assertSame($same?1:2,$rows->count());
            $actual=$rows->modelKeys(); $expected=$same?[$ra['id']]:[$ra['id'],$rb['id']];
            sort($actual);sort($expected);$this->assertSame($expected,$actual);
            $this->assertSame($same?1:2,DB::table('appointment_user')->whereIn('appointment_id',$rows->modelKeys())->count());
            $this->finish();
            $this->assertWorkersDisconnected([$readyA['connection'],$readyB['connection']]);
        } finally {
            $this->stopAppointmentWorkers();
            if($client) {
                Cita::where('client_id',$client->id)->delete();
                // Fixture propio sin documentos financieros; delete() conservaría SoftDeletes.
                $client->forceDelete();
                $this->assertDatabaseMissing('clients',['id'=>$client->id]);
            }
            if($service) $service->delete();
            foreach($users as $user) $user->delete();
            $role->delete();
        }
    }
}
