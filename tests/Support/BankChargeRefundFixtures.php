<?php
namespace Tests\Support;
use App\Services\{AccountingBankChargeSimulation,BankChargeRefundSimulation};
use App\Support\AccountingSimulationBoundary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
trait BankChargeRefundFixtures
{
    use BankReconciliationFixtures;
    protected function refundCase(string $month='2034-03'): array {
        Carbon::setTestNow();$this->policyActor();$b=$this->bankFixture(0);$b->update(['alias'=>'AC04B2B3A SYNTHETIC '.bin2hex(random_bytes(5))]);
        [$v]=$this->pilotPolicy($month.'-01T00:00:00Z',Carbon::parse($month.'-01')->addMonth()->format('Y-m-d').'T00:00:00Z',$b);$g=$this->simulationGrant($v,$b);
        $this->bankDate($month.'-10');$seed=$this->documentedEntry($b,1000);$input=array_replace($this->pilotInput(),['recognition_date'=>$month.'-10']);
        $o=app(AccountingBankChargeSimulation::class)->executeForInternalHarness($g->id,$input,AccountingSimulationBoundary::CONTRACT);
        $this->bankDate($month.'-20');return [$b,$o,$this->refundInput($month.'-15'),$g,$seed];
    }
    protected function refundInput(string $day): array {return ['uuid'=>(string)\Illuminate\Support\Str::uuid(),'reason_code'=>'credited_full_refund','bank_reference'=>'SYNTHETIC-BANK-CREDIT','authorization_reference'=>'SYNTHETIC-ACCOUNTING-AUTH','evidence_description'=>'Ensayo: comprobante sintético de devolución completa acreditada, sin aprobación económica real.','effective_date'=>$day,'recognition_date'=>$day];}
    protected function refund(object $o,array $input): object {return app(BankChargeRefundSimulation::class)->execute((int)$o->id,$input,BankChargeRefundSimulation::CONTRACT);}
    protected function clearOriginal(object $b,object $o,object $seed,string $month='2034-03'): object {
        $this->post('/bancos/'.$b->id.'/conciliacion',$this->reconciliationPayload($month.'-11',950,[$seed->id,$o->result_movement_id]))->assertSessionHasNoErrors();
        return DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$b->id)->orderByDesc('id')->first();
    }
}
