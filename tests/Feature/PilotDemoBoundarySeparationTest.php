<?php
namespace Tests\Feature;

use App\Support\{AccountingHttpSimulationBoundary,AccountingSimulationBoundary,AccountingSimulationPosting};
use Tests\TestCase;

final class PilotDemoBoundarySeparationTest extends TestCase
{
    public function test_final_http_route_replays_original_without_financial_mutation(): void {
        $f=json_decode(file_get_contents(storage_path('app/private/ac04b2b4b-http-fixture.json')),true,512,JSON_THROW_ON_ERROR);
        $before=[];foreach(['accounting_event_operations','accounting_entry_line_resolutions','movimientos_bancarios','asientos_contables','detalle_asientos','cuentas_bancarias'] as $table)$before[$table]=\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson();
        $user=\App\Models\Usuario::findOrFail($f['actor']);$client=new \Tests\Support\PilotHttpClient(8775);$server=$client->startServer();
        try{
            $this->assertSame(302,$client->login($user->email,'SYNTHETIC-AC04B2B4B-LOCAL-TEST-ONLY'));$r=$client->confirm($f['input']+['authorization_id'=>$f['grant']]);$this->assertSame(302,$r['status']);
            $this->assertSame(200,$client->request('/__pilot-demo/operations/'.$f['input']['uuid'])['status']);
            $originalCurrency=\Illuminate\Support\Facades\DB::table('cuentas_bancarias')->where('id',$f['bank'])->value('moneda_id');
            try{
                \Illuminate\Support\Facades\DB::table('cuentas_bancarias')->where('id',$f['bank'])->update(['moneda_id'=>\Illuminate\Support\Facades\DB::table('monedas')->where('codigo','USD')->value('id')]);
                $this->assertSame(200,$client->request('/__pilot-demo/operations/'.$f['input']['uuid'])['status']);
                $this->assertSame(302,$client->confirm($f['input']+['authorization_id'=>$f['grant']])['status']);
            }finally{\Illuminate\Support\Facades\DB::table('cuentas_bancarias')->where('id',$f['bank'])->update(['moneda_id'=>$originalCurrency]);}
            foreach($before as $table=>$rows)$this->assertSame($rows,\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson());
        }finally{proc_terminate($server);$status=proc_get_status($server);proc_close($server);$this->assertFalse($status['running']);}
    }

    public function test_original_cli_entries_do_not_accept_the_http_contract(): void {
        $user=\App\Models\Usuario::where('is_active',true)->whereIn('role',['alvaro','contador'])->firstOrFail();$this->actingAs($user);
        AccountingSimulationBoundary::assert(AccountingSimulationBoundary::CONTRACT);
        foreach([
            fn()=>AccountingSimulationBoundary::assert(AccountingHttpSimulationBoundary::CONTRACT),
            fn()=>app(\App\Services\AccountingBankChargeSimulation::class)->executeForInternalHarness(1,[],AccountingHttpSimulationBoundary::CONTRACT),
            fn()=>app(\App\Services\AccountingAccountResolver::class)->inspectPinnedForInternalHarness([],new \DateTimeImmutable,1,AccountingHttpSimulationBoundary::CONTRACT),
            fn()=>AccountingSimulationPosting::forPrepared(1,1,new \DateTimeImmutable,AccountingHttpSimulationBoundary::CONTRACT),
        ] as $call){try{$call();$this->fail('La entrada CLI no debe admitir HTTP.');}catch(\LogicException $e){$this->assertNotSame('',$e->getMessage());}}
        try{AccountingHttpSimulationBoundary::assert(AccountingHttpSimulationBoundary::CONTRACT);$this->fail('CLI no es el servidor HTTP.');}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(404,$e->getStatusCode());}
        $this->get('/__pilot-demo/login')->assertNotFound();$this->post('/__pilot-demo/confirm',[])->assertNotFound();
    }
}
