<?php

namespace Tests\Feature;

use App\Models\{AsientoContable,PeriodoContable};
use App\Services\AccountingReportExtractor;
use App\Support\ReportDemoBoundary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\{AccountingPolicyFixtures,ReportPresentationFixtures};
use Tests\TestCase;

class ReportPresentationDemoTest extends TestCase
{
    use DatabaseTransactions,AccountingPolicyFixtures;
    protected function setUp(): void {parent::setUp();$this->policyActor();}
    public function test_offline_provenance_correctors_hierarchy_and_zero_net_history_are_visible(): void
    {
        $r=ReportPresentationFixtures::compare('overview','balance');
        $this->assertStringContainsString('NO extraídos de MySQL',$r['data_origin']);$this->assertNull($r['snapshot']);
        $this->assertSame(-1000,$r['synthetic']['accounts'][105]['contribution_cents']);
        $this->assertSame(-500,$r['synthetic']['accounts'][106]['contribution_cents']);
        $this->assertFalse($r['synthetic']['details'][12]['current_account']['active']);
        $this->assertSame(200,$r['synthetic']['accounts'][108]['contribution_cents']);
        $this->assertSame(400,$r['synthetic']['accounts'][109]['contribution_cents']);
        $this->assertSame(0,$r['synthetic']['balance_difference_cents']);$this->assertSame([],$r['legacy_reported_global']);
        $html=view('accounting.report-demo',['comparison'=>$r,'problem'=>null])->render();
        foreach(['A · Totales','B / C / D','D · Diferencias por cuenta','D · Detalles originales','-10.00','inactiva con historia','subconjunto explícito'] as $text)$this->assertStringContainsString($text,$html);
        $this->assertStringContainsString('&lt;b&gt;etiqueta literal&lt;/b&gt;',$html);$this->assertStringNotContainsString('<b>etiqueta literal</b>',$html);
        $this->assertMatchesRegularExpression('/value="balance"\s+selected/',$html);
        $this->assertStringContainsString('Contribución legado:',$html);
        $this->assertSame(-1000,$r['synthetic']['detail_contributions_cents'][8]);
    }
    public function test_corrected_closing_results_and_balance_do_not_double_profit(): void
    {
        $r=ReportPresentationFixtures::compare('closing','result');$b=ReportPresentationFixtures::compare('closing','balance');
        $this->assertSame(9000,$r['synthetic']['subtotals_cents']['result']);$this->assertCount(3,$r['synthetic']['details']);
        $this->assertSame(0,$b['synthetic']['pending_result_cents']);$this->assertSame(9000,$b['synthetic']['groups_cents']['equity']);
        $this->assertCount(6,$b['synthetic']['details']);$this->assertSame(0,$b['synthetic']['balance_difference_cents']);
    }
    public function test_incomplete_coverage_cannot_show_a_successful_synthetic_statement(): void
    {
        $r=ReportPresentationFixtures::compare('incomplete','result');$this->assertNull($r['synthetic']);
        $this->assertSame([103],$r['warnings'][0]['account_ids']);$this->assertSame([],$r['differences']['details']);
        $html=view('accounting.report-demo',['comparison'=>$r,'problem'=>null])->render();
        $this->assertStringContainsString('Cobertura incompleta',$html);$this->assertStringContainsString('No disponibles para offline',$html);
    }
    public function test_null_states_period_filters_remain_declared_and_distinct(): void
    {
        $all=ReportPresentationFixtures::compare('overview','result');$strict=ReportPresentationFixtures::compare('overview','result','posted','exclude','fixture1');
        $this->assertSame(11400,$all['synthetic']['groups_cents']['income']);$this->assertSame(9000,$strict['synthetic']['groups_cents']['income']);
        $this->assertSame(-1000,$all['legacy']['groups_cents']['income']);
        $this->assertContains('legacy_filters_date_not_period_id',array_column($strict['warnings'],'kind'));
    }
    public function test_offline_comparison_memory_is_bounded_and_overflow_rejected(): void
    {
        $before=memory_get_usage(true);for($i=0;$i<100;$i++){$r=ReportPresentationFixtures::compare('overview','balance');unset($r);}
        $this->assertLessThan(32*1024*1024,memory_get_usage(true)-$before);
        $this->expectException(\DomainException::class);ReportPresentationFixtures::assertVolume(ReportPresentationFixtures::MAX_ROWS+1);
    }
    public function test_sql_volume_limit_rejects_before_materializing_legacy_or_source_rows(): void
    {
        $p=PeriodoContable::create(['nombre'=>'AC05B3A VOLUME ROLLBACK ONLY','fecha_inicio'=>'2031-07-01','fecha_fin'=>'2031-07-31','estado'=>'abierto']);
        $e=AsientoContable::create(['numero_asiento'=>'AC05B3A-'.bin2hex(random_bytes(6)),'fecha'=>'2031-07-15','concepto'=>'Volume fixture',
            'periodo_id'=>$p->id,'usuario_id'=>auth()->id(),'modulo_origen'=>'manual']);
        $account=DB::table('cuentas_contables')->where('permite_movimiento',true)->value('id');$currency=DB::table('monedas')->where('es_base',true)->value('id');
        $row=['asiento_id'=>$e->id,'cuenta_id'=>$account,'moneda_id'=>$currency,'debe'=>'0.00','haber'=>'0.00'];
        foreach(array_chunk(array_fill(0,AccountingReportExtractor::MAX_LEDGER_DETAILS+1,$row),200) as $chunk)DB::table('detalle_asientos')->insert($chunk);
        $materialized=false;DB::connection()->beforeExecuting(function($sql)use(&$materialized){if(str_contains($sql,'`detalle_asientos` as `d`'))$materialized=true;});
        try{
            app(AccountingReportExtractor::class)->compare(['contract'=>'synthetic-presentation-v1','purpose'=>'simulation','version'=>'LIMIT-TEST','assignments'=>[]],
                ['report'=>'balance','from'=>null,'until'=>'2031-07-31','states'=>['contabilizado'],'null_origin'=>'include','account_scope'=>'all','center_scope'=>'all']);
            $this->fail('Must not silently truncate.');
        }catch(\DomainException $x){$this->assertStringContainsString('volume limit',$x->getMessage());$this->assertFalse($materialized);}
    }
    public function test_report_http_boundary_cannot_be_acquired_by_cli_or_ordinary_routes(): void
    {
        $this->get('/__report-demo')->assertNotFound();$this->post('/__report-demo/compare')->assertNotFound();
        try{ReportDemoBoundary::installation();$this->fail('CLI cannot acquire HTTP boundary.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(404,$e->getStatusCode());}
    }
    public function test_role_gate_has_no_admin_bypass_and_requires_active_actor(): void
    {
        foreach(['alvaro'=>true,'contador'=>true,'admin'=>false,'cajera'=>false,'recepcion'=>false] as $role=>$expected)
            $this->assertSame($expected,ReportDemoBoundary::allowsActor((object)['role'=>$role,'is_active'=>1]));
        $this->assertFalse(ReportDemoBoundary::allowsActor((object)['role'=>'contador','is_active'=>0]));
        $this->assertFalse(ReportDemoBoundary::allowsActor(null));
    }
    public function test_invalid_array_filter_is_not_rendered_as_authoritative_or_html(): void
    {
        request()->merge(['month'=>['2030-10'],'until'=>['<script>bad</script>']]);
        $html=view('accounting.report-demo',['comparison'=>null,'problem'=>'Comparación rechazada'])->render();
        $this->assertStringContainsString('Comparación rechazada',$html);
        $this->assertStringNotContainsString('<script>bad</script>',$html);
    }
}
