<?php

namespace Tests\Support;

use App\Services\{AccountingBankChargeSimulation,AccountingEventAuthorizationService};
use App\Support\AccountingSimulationBoundary;
use Carbon\Carbon;

trait AccountingPilotFixtures
{
    use AccountingEventFixtures,BankBalanceFixtures;
    protected function pilotCase(string $month='2034-01'): array {
        Carbon::setTestNow();
        $this->policyActor();$bank=$this->bankFixture();
        $until=Carbon::parse($month.'-01')->addMonth()->format('Y-m-d').'T00:00:00Z';
        [$v]=$this->pilotPolicy($month.'-01T00:00:00Z',$until,$bank);$g=$this->simulationGrant($v,$bank);
        $this->bankDate($month.'-15');$p=$this->pilotInput();$p['recognition_date']=$month.'-15';return [$bank,$g,$p,$v];
    }
    protected function runPilot(object $g,array $input): object {
        return app(AccountingBankChargeSimulation::class)->executeForInternalHarness($g->id,$input,AccountingSimulationBoundary::CONTRACT);
    }
}
