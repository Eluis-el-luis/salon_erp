<?php
namespace Tests\Feature;
use App\Models\Usuario;
use App\Services\PilotDemoReadOnly;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Hash};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
class PilotDemoReadOnlyTest extends TestCase
{
    use DatabaseTransactions;
    protected function setUp(): void {
        parent::setUp();if(!defined('SALON_PILOT_DEMO_READONLY'))define('SALON_PILOT_DEMO_READONLY',PilotDemoReadOnly::CONTRACT);
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,EnforcedPilotDemoCsrf::class);$this->withSession(['_token'=>'pilot-demo-test-csrf']);
    }
    private function owner(): object {
        $o=DB::table('accounting_event_operations')->where('id',131)->first();$this->assertNotNull($o);$this->actingAs(Usuario::findOrFail($o->created_by));return $o;
    }
    private function financial(): array {$a=[];foreach(['cuentas_bancarias','accounting_event_operations','accounting_policy_authorizations','movimientos_bancarios','asientos_contables','detalle_asientos','conciliaciones_bancarias','bank_charge_reversals','bank_charge_reversal_lines'] as $t)$a[$t]=DB::table($t)->orderBy('id')->get()->toJson();return $a;}
    public function test_render_prepared_confirmed_original_and_refund_with_no_mutation(): void {
        $prepared=$this->owner();$before=$this->financial();$this->get('/__pilot-demo')->assertOk()->assertSee('INFORMACIÓN SINTÉTICA')->assertSee('Revisar sin registrar');
        $this->get('/__pilot-demo/operations/'.$prepared->operation_uuid)->assertOk()->assertSee('Prepared no equivale a éxito financiero')->assertDontSee('Asiento original #');
        $refund=DB::table('bank_charge_reversals')->where('created_by',auth()->id())->where('status','confirmed')->first();$o=DB::table('accounting_event_operations')->find($refund->original_operation_id);
        $this->get('/__pilot-demo/operations/'.$o->operation_uuid)->assertOk()->assertSee('Asiento original #')->assertSee('Correspondencias originales')->assertSee('Devolución acreditada sintética')->assertSee((string)$refund->result_asiento_id);
        $revoked=DB::table('accounting_event_operations AS o')->join('accounting_policy_authorizations AS r',function($j){$j->on('r.parent_id','=','o.authorization_id')->where('r.action','revocation');})->where('o.created_by',auth()->id())->where('o.status','confirmed')->select('o.*')->first();$this->assertNotNull($revoked);
        $this->get('/__pilot-demo/operations/'.$revoked->operation_uuid)->assertOk()->assertSee('retirada; historia conservada');
        $this->assertSame($before,$this->financial());
    }
    public function test_review_preserves_uuid_hash_and_has_no_confirmation_route(): void {
        $o=$this->owner();$content=json_decode($o->content,true);$p=['authorization_id'=>$o->authorization_id,'uuid'=>$o->operation_uuid,'classification'=>$o->classification,'amount'=>$o->amount,'recognition_date'=>$o->recognition_date,'reference'=>$content['reference'],'description'=>$content['description']];$before=$this->financial();
        $this->post('/__pilot-demo/review',$p)->assertStatus(419);
        $this->post('/__pilot-demo/review',$p+['_token'=>'pilot-demo-test-csrf'])->assertOk()->assertSee($o->content_hash)->assertSee('Confirmar cargo — bloqueado')->assertSee('prepared');
        $p['amount']='51.00';$this->post('/__pilot-demo/review',$p+['_token'=>'pilot-demo-test-csrf'])->assertStatus(409);
        $this->post('/__pilot-demo/confirm',$p+['_token'=>'pilot-demo-test-csrf'])->assertNotFound();$this->post('/__pilot-demo/refund',$p+['_token'=>'pilot-demo-test-csrf'])->assertNotFound();$this->assertSame($before,$this->financial());
    }
    public static function invalid(): array {return [['amount',''],['amount','-1'],['amount','0'],['amount','50.001'],['amount','NaN'],['classification',''],['classification','transfer'],['account_id',1],['policy_version_id',1],['actor_id',1]];}
    #[DataProvider('invalid')]
    public function test_invalid_review_never_writes(string $field,mixed $value): void {
        $o=$this->owner();$p=['authorization_id'=>$o->authorization_id,'uuid'=>(string)\Illuminate\Support\Str::uuid(),'classification'=>'maintenance','amount'=>'50.00','recognition_date'=>$o->recognition_date,'reference'=>'SYNTHETIC-READ-ONLY','description'=>'Revisión sin persistencia'];$p[$field]=$value;$before=$this->financial();
        $this->post('/__pilot-demo/review',$p+['_token'=>'pilot-demo-test-csrf'])->assertRedirect()->assertSessionHasErrors();$this->assertSame($before,$this->financial());
    }
    public function test_roles_active_identity_resource_owner_and_login_session(): void {
        $o=$this->owner();$role=DB::table('roles')->value('id');$actors=[];
        foreach(['alvaro','contador','admin','recepcion','estilista'] as $r)$actors[$r]=Usuario::create(['name'=>'SYNTHETIC demo '.$r,'email'=>uniqid('demo-').'@example.test','password'=>Hash::make('SYNTHETIC-demo-password'),'role'=>$r,'role_id'=>$role,'is_active'=>true]);
        foreach($actors as $r=>$actor){$this->actingAs($actor);$this->get('/__pilot-demo')->assertStatus(in_array($r,['alvaro','contador'])?200:403);if(in_array($r,['alvaro','contador']))$this->get('/__pilot-demo/operations/'.$o->operation_uuid)->assertForbidden();}
        $this->actingAs($actors['contador']);$actors['contador']->update(['is_active'=>false]);$this->get('/__pilot-demo')->assertForbidden();
        auth()->logout();$this->get('/__pilot-demo')->assertRedirect('/__pilot-demo/login');$this->get('/__pilot-demo/login')->assertOk();
        $this->post('/__pilot-demo/login',['email'=>$actors['alvaro']->email,'password'=>'SYNTHETIC-demo-password'])->assertStatus(419);
        $this->post('/__pilot-demo/login',['_token'=>'pilot-demo-test-csrf','email'=>$actors['alvaro']->email,'password'=>'SYNTHETIC-demo-password'])->assertRedirect('/__pilot-demo');$this->get('/__pilot-demo')->assertOk();
    }
    public function test_installation_wrong_destination_and_database_read_only_are_enforced(): void {
        $o=$this->owner();$this->app->instance('env','local');$this->get('/__pilot-demo')->assertNotFound();$this->app->instance('env','testing');
        $old=config('database.connections.mysql.database');config(['database.connections.mysql.database'=>'salon_erp']);$this->get('/__pilot-demo')->assertNotFound();config(['database.connections.mysql.database'=>$old]);
        $service=new PilotDemoReadOnly;$service->index();$property=new \ReflectionProperty($service,'pdo');$pdo=$property->getValue($service);
        try{$pdo->exec('UPDATE cuentas_bancarias SET saldo_actual=saldo_actual WHERE id='.(int)$o->bank_account_id);$this->fail('El servidor debe rechazar DML');}catch(\PDOException $e){$this->assertSame(1792,$e->errorInfo[1]);}
        $this->assertSame('salon_erp_test',$pdo->query('SELECT DATABASE()')->fetchColumn());
    }
}
class EnforcedPilotDemoCsrf extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery {protected function runningUnitTests(){return false;}}
