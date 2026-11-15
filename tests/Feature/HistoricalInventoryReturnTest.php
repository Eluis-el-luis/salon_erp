<?php

namespace Tests\Feature;

use App\Exceptions\ContabilidadException;
use App\Models\{Cita, Cliente, Venta};
use App\Services\{AgendaService, AprobacionService, InventarioFraccionableService};
use App\Support\InventoryDecimal as D;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{FractionalV2Fixtures, InventoryH02Fixtures};
use Tests\TestCase;

class HistoricalInventoryReturnTest extends TestCase
{
    use DatabaseTransactions, FractionalV2Fixtures, InventoryH02Fixtures;

    protected function setUp(): void
    {
        parent::setUp(); $this->actorH02(); config(['salon.inventory_fractional_v2'=>true]);
    }

    protected function evidence(string $intent = 'sin_restitucion'): array
    {
        return ['motivo'=>'Anulación documentada de ensayo','pin_supervisor'=>'4826','inventory_intent'=>$intent,
            'verification_reference'=>'ACTA-H04','verification_description'=>'Material recibido y verificado en ensayo',
            'verified_on'=>today()->toDateString(),'verification_confirmed'=>'1',
            'execution_declaration'=>'prestada','execution_reference'=>'CONST-H04','execution_description'=>'Atención constatada retrospectivamente en acta',
            'execution_verified_on'=>today()->toDateString(),'execution_confirmed'=>'1'];
    }

    private function fixture(bool $appointment = false, $amount = 20): array
    {
        $i=$this->itemV2(100); $this->buyV2($i,2,'10');
        $s=$this->serviceV2([$i->id=>$amount]); $line=$this->lineV2($s);
        $c=null;
        if ($appointment) {
            $c=app(AgendaService::class)->reservar(['client_id'=>Cliente::create(['name'=>'H04 fixture'])->id,
                'service_id'=>$s->id,'stylists'=>[auth()->id()],'appointment_date'=>'2026-11-20 10:00:00']);
            $line['appointment_id']=$c->id;
        }
        $id=$this->postJson('/sales',['cart'=>[$line]])->assertCreated()->json('sale_id');
        return [$i,$s,$c,Venta::findOrFail($id),$line];
    }

    private function balances(): array
    {
        $b=[];
        foreach (DB::table('detalle_asientos as d')->join('cuentas_contables as c','c.id','=','d.cuenta_id')->get(['c.codigo','d.debe','d.haber']) as $l)
            $b[$l->codigo]=($b[$l->codigo]??0)+D::stored($l->debe)-D::stored($l->haber);
        ksort($b); return $b;
    }

    private function snapshot(): array
    {
        $r=[];
        foreach (['items','lotes','sales','appointments','stock_ledger_entries','stock_ledger_lot_allocations','pagos','asientos_contables','detalle_asientos','comisiones_generadas'] as $t)
            $r[$t]=DB::table($t)->orderBy('id')->get()->toJson();
        return $r;
    }

    public function test_performed_without_return_preserves_cost_stock_occupancy_and_blocks_all_ordinary_paths(): void
    {
        [$i,$s,$c,$sale,$line]=$this->fixture(true); $before=$this->balances();
        $applied=$this->snapshot();
        $this->postJson('/sales',['cart'=>[$line]])->assertUnprocessable();
        $this->post('/sales/bill-appointment/'.$c->id,[])->assertUnprocessable();
        $this->assertSame($applied,$this->snapshot());
        $ledger=DB::table('stock_ledger_entries')->count(); $alloc=DB::table('stock_ledger_lot_allocations')->count();
        $this->post('/ventas/'.$sale->id.'/anular',$this->evidence())->assertSessionHas('success');
        $this->assertSame('completada',$c->fresh()->status);
        $e=$sale->fresh()->annulment_evidence;
        $this->assertTrue($e['appointment_execution']['retrospective']);
        $this->assertContains(\App\Models\Usuario::findOrFail($e['supervisor_id'])->role,config('salon.seguridad.roles_supervisor'));
        $this->assertSame(auth()->id(),$e['appointment_execution']['verifier_id']);
        $this->assertNotEmpty($e['recorded_at']); $this->assertStringNotContainsString('"pin_supervisor":',json_encode($e));
        $after=$this->balances();
        $this->assertSame($before['5.2'],$after['5.2']); $this->assertSame($before['1.1.5'],$after['1.1.5']);
        $this->assertSame($before['4.1']+10000,$after['4.1']); $this->assertSame($before['1.1.1']-10000,$after['1.1.1']);
        foreach (DB::table('asientos_contables')->where('referencia_id',$sale->id)->whereIn('modulo_origen',['ventas','inventario'])->get() as $a) {
            $lines=DB::table('detalle_asientos')->where('asiento_id',$a->id)->get();
            $this->assertSame($lines->sum(fn($l)=>D::stored($l->debe)),$lines->sum(fn($l)=>D::stored($l->haber)));
        }
        $this->assertSame($ledger,DB::table('stock_ledger_entries')->count()); $this->assertSame($alloc,DB::table('stock_ledger_lot_allocations')->count());
        $frozen=$this->snapshot();
        $this->postJson('/sales',['cart'=>[$line]])->assertUnprocessable();
        $this->post('/sales/bill-appointment/'.$c->id,[])->assertUnprocessable();
        $this->putJson('/api/appointments/'.$c->id,['status'=>'pendiente'])->assertUnprocessable();
        $this->postJson('/sales',['cart'=>[$line],'action'=>'cotizar'])->assertUnprocessable();
        $this->assertSame($frozen,$this->snapshot());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AgendaService::class)->reservar(['client_id'=>$c->client_id,'service_id'=>$s->id,'stylists'=>[auth()->id()],'appointment_date'=>$c->appointment_date]);
    }

    public static function returnModes(): array { return [['devolucion_completa'],['correccion_completa']]; }

    #[DataProvider('returnModes')]
    public function test_volume_return_restores_same_open_container_at_historical_value_without_resealing(string $intent): void
    {
        [$i,$s,$c,$sale]=$this->fixture(); $before=$this->balances();
        $original=DB::table('stock_ledger_lot_allocations')->where('sale_detail_id',$sale->detalles->first()->id)->first();
        $this->post('/ventas/'.$sale->id.'/anular',$this->evidence($intent))->assertSessionHas('success');
        $i->refresh(); $this->assertSame(1,(int)$i->existencia_actual); $this->assertSame('100.00',$i->current_volume);
        $state=app(InventarioFraccionableService::class)->estado($i); $this->assertSame(20000,$state['q']); $this->assertSame(2000,$state['v']);
        $inverse=DB::table('stock_ledger_lot_allocations')->where('reverses_allocation_id',$original->id)->sole();
        $this->assertSame(-D::signed($original->value_delta),D::signed($inverse->value_delta));
        $this->assertSame($original->lote_id,$inverse->lote_id);
        $after=$this->balances(); $this->assertSame(200,$before['5.2']-$after['5.2']); $this->assertSame(200,$after['1.1.5']-$before['1.1.5']);
        $frozen=$this->snapshot(); $this->post('/ventas/'.$sale->id.'/anular',$this->evidence('devolucion_completa'))->assertSessionHas('error');
        $this->assertSame($frozen,$this->snapshot());
    }

    public function test_direct_closed_product_returns_to_original_lot_after_new_purchase_and_archive(): void
    {
        $i=$this->itemV2(100); $this->buyV2($i,1,'10');
        $id=$this->postJson('/sales',['cart'=>[$this->cartH02($i)]])->assertCreated()->json('sale_id');
        $original=DB::table('stock_ledger_lot_allocations')->where('physical_delta','<',0)->where('item_id',$i->id)->sole();
        $this->buyV2($i,1,'30'); $i->delete();
        $this->post('/ventas/'.$id.'/anular',$this->evidence('correccion_completa'))->assertSessionHas('success');
        $this->assertSame(2,(int)$i->fresh()->existencia_actual);
        $inverse=DB::table('stock_ledger_lot_allocations')->where('reverses_allocation_id',$original->id)->sole();
        $this->assertSame($original->lote_id,$inverse->lote_id); $this->assertSame('10.00',$inverse->value_delta);
    }

    public function test_same_lot_new_container_does_not_fake_continuity_and_entire_operation_rolls_back(): void
    {
        [$i,$s,$c,$sale]=$this->fixture();
        $this->postJson('/sales',['cart'=>[$this->lineV2($this->serviceV2([$i->id=>100]))]])->assertCreated();
        $before=$this->snapshot();
        $this->post('/ventas/'.$sale->id.'/anular',$this->evidence('devolucion_completa'))->assertSessionHas('error');
        $this->assertSame($before,$this->snapshot());
    }

    public static function invalidEvidence(): array
    {
        return [['execution_reference',''],['execution_declaration','no_determinada'],['execution_declaration','no_prestada'],['execution_confirmed','0'],['inventory_intent',''],['execution_verified_on','2099-01-01']];
    }

    #[DataProvider('invalidEvidence')]
    public function test_invalid_or_unresolved_execution_is_rejected_without_effects(string $field,string $value): void
    {
        [$i,$s,$c,$sale]=$this->fixture(true); $before=$this->snapshot();
        $this->post('/ventas/'.$sale->id.'/anular',array_replace($this->evidence(),[$field=>$value]));
        $this->assertSame($before,$this->snapshot());
    }

    public function test_zero_consumption_has_no_return_or_cost_and_no_execution_is_inferred(): void
    {
        [$i,$s,$c,$sale]=$this->fixture(true,0);
        $n=DB::table('stock_ledger_entries')->count();
        $data=$this->evidence(); $data['execution_declaration']='no_prestada';
        $this->post('/ventas/'.$sale->id.'/anular',$data)->assertSessionHas('success');
        $this->assertSame('pendiente',$c->fresh()->status); $this->assertSame($n,DB::table('stock_ledger_entries')->count());
        $this->assertNull($sale->fresh()->annulment_evidence['physical_verification']);
    }

    public static function failures(): array { return [['domain'], ['error']]; }

    #[DataProvider('failures')]
    public function test_failure_after_physical_writes_rolls_back_everything(string $kind): void
    {
        [$i,$s,$c,$sale]=$this->fixture(true); $before=$this->snapshot(); $hit=false; $level=DB::transactionLevel();
        DB::connection()->beforeExecuting(function ($sql) use (&$hit,$kind) {
            if (!$hit && str_starts_with(strtolower($sql),'insert into `detalle_asientos`')) {
                $hit=true;
                if ($kind==='error') throw new \TypeError('Error H04 inyectado');
                throw new ContabilidadException('Fallo H04 inyectado');
            }
        });
        $this->post('/ventas/'.$sale->id.'/anular',$this->evidence('devolucion_completa'))->assertSessionHas('error');
        $this->assertTrue($hit); $this->assertSame($level,DB::transactionLevel()); $this->assertSame($before,$this->snapshot());
    }

    public function test_retained_approval_cannot_reuse_a_blocked_execution_and_notes_remain_editable(): void
    {
        [$i,$s,$c,$sale,$line]=$this->fixture(true);
        $retained=Venta::create(['cashier_id'=>auth()->id(),'appointment_id'=>$c->id,'client_id'=>$c->client_id,
            'subtotal'=>100,'discount'=>0,'total'=>100,'status'=>'pendiente_aprobacion','currency'=>'nio','exchange_rate'=>1,'payment_method'=>'efectivo']);
        $this->post('/ventas/'.$sale->id.'/anular',$this->evidence())->assertSessionHas('success'); $before=$this->snapshot();
        try {app(AprobacionService::class)->aprobarVenta($retained->id); $this->fail('Approval must reject');} catch (ContabilidadException $e) {$this->assertStringContainsString('ejecución',$e->getMessage());}
        $this->assertSame($before,$this->snapshot());
        $edit=app(AgendaService::class)->paraEditar($c->id);
        app(AgendaService::class)->editar($c->id,['edit_token'=>$edit['edit_token'],'notes'=>'Nota histórica H04']);
        $this->assertSame('completada',$c->fresh()->status);
    }

    public function test_completed_never_invoiced_appointment_can_be_billed_normally(): void
    {
        $s=$this->serviceV2([]);
        $c=app(AgendaService::class)->reservar(['client_id'=>Cliente::create(['name'=>'H04 manual'])->id,
            'service_id'=>$s->id,'stylists'=>[auth()->id()],'appointment_date'=>'2026-11-20 10:00:00']);
        app(AgendaService::class)->cambiarEstado($c->id,'completada');
        $this->post('/sales/bill-appointment/'.$c->id,[])->assertOk();
        $this->assertSame(1,Venta::where('appointment_id',$c->id)->count());
    }

    public function test_missing_physical_verification_is_rejected_and_identity_payload_is_not_authoritative(): void
    {
        [$i,$s,$c,$sale]=$this->fixture(); $before=$this->snapshot();
        $data=$this->evidence('devolucion_completa'); unset($data['verification_reference']);
        $this->post('/ventas/'.$sale->id.'/anular',$data)->assertSessionHasErrors('verification_reference');
        $this->assertSame($before,$this->snapshot());
        $data=$this->evidence('devolucion_completa')+['supervisor_id'=>999999,'verifier_id'=>999999,'quantity'=>9999,'original_movement_ids'=>[999999]];
        $this->post('/ventas/'.$sale->id.'/anular',$data)->assertSessionHas('success');
        $e=$sale->fresh()->annulment_evidence;
        $this->assertNotSame(999999,$e['supervisor_id']); $this->assertSame(auth()->id(),$e['physical_verification']['verifier_id']);
        $this->assertNotContains(999999,$e['original_movement_ids']);
    }

    public function test_other_lot_open_blocks_entire_multi_article_return(): void
    {
        $a=$this->itemV2(100); $b=$this->itemV2(100); $this->buyV2($a,2,'10'); $this->buyV2($b,1,'10');
        $s=$this->serviceV2([$a->id=>20,$b->id=>100]);
        $id=$this->postJson('/sales',['cart'=>[$this->lineV2($s)]])->assertCreated()->json('sale_id');
        $this->buyV2($b,1,'30');
        $this->postJson('/sales',['cart'=>[$this->lineV2($this->serviceV2([$b->id=>20]))]])->assertCreated();
        $before=$this->snapshot();
        $this->post('/ventas/'.$id.'/anular',$this->evidence('devolucion_completa'))->assertSessionHas('error');
        $this->assertSame($before,$this->snapshot());
    }

    public function test_new_purchase_and_later_consumption_preserve_same_open_continuity(): void
    {
        [$i,$s,$c,$sale]=$this->fixture(); $this->buyV2($i,1,'30');
        $this->postJson('/sales',['cart'=>[$this->lineV2($this->serviceV2([$i->id=>10]))]])->assertCreated();
        $this->post('/ventas/'.$sale->id.'/anular',$this->evidence('devolucion_completa'))->assertSessionHas('success');
        $this->assertSame('90.00',$i->fresh()->current_volume);
        $this->assertSame(2,(int)$i->fresh()->existencia_actual);
    }

    public function test_retained_document_without_effects_records_no_receipt_and_does_not_classify_execution(): void
    {
        $s=$this->serviceV2([]);
        $line=$this->lineV2($s);
        $id=$this->postJson('/sales',['cart'=>[$line],'action'=>'cotizar'])->assertCreated()->json('sale_id');
        $n=DB::table('stock_ledger_entries')->count(); $a=DB::table('asientos_contables')->count();
        $this->post('/ventas/'.$id.'/anular',$this->evidence())->assertSessionHas('success');
        $this->assertSame($n,DB::table('stock_ledger_entries')->count()); $this->assertSame($a,DB::table('asientos_contables')->count());
        $e=Venta::findOrFail($id)->annulment_evidence; $this->assertFalse($e['effects_applied']);
        $this->assertArrayNotHasKey('appointment_execution',$e); $this->assertNull($e['physical_verification']);
    }

    public function test_partial_historical_assignments_fail_without_reinterpretation(): void
    {
        [$i,$s,$c,$sale]=$this->fixture();
        DB::table('sale_details')->where('sale_id',$sale->id)->update(['consumption_snapshot'=>null]); // Fixture histórico ambiguo.
        $before=$this->snapshot();
        $this->post('/ventas/'.$sale->id.'/anular',$this->evidence('devolucion_completa'))->assertSessionHas('error');
        $this->assertSame($before,$this->snapshot());
    }

    public function test_different_cost_lots_preserve_each_original_cent_and_account_on_return(): void
    {
        $i=$this->itemV2(100); $this->buyV2($i,1,'10.01'); $this->buyV2($i,2,'10');
        $id=$this->postJson('/sales',['cart'=>[$this->cartH02($i,3)]])->assertCreated()->json('sale_id');
        $sale=Venta::findOrFail($id); $originals=DB::table('stock_ledger_lot_allocations')->where('sale_detail_id',$sale->detalles->first()->id)->get();
        $this->assertCount(2,$originals); $this->assertSame(-3001,$originals->sum(fn($a)=>D::signed($a->value_delta)));
        $this->buyV2($i,1,'40'); $before=$this->balances();
        $this->post('/ventas/'.$id.'/anular',$this->evidence('devolucion_completa'))->assertSessionHas('success');
        foreach ($originals as $a) {
            $inverse=DB::table('stock_ledger_lot_allocations')->where('reverses_allocation_id',$a->id)->sole();
            $this->assertSame(-D::signed($a->value_delta),D::signed($inverse->value_delta));
            $this->assertSame(-D::signed($a->physical_delta),D::signed($inverse->physical_delta));
        }
        $after=$this->balances(); $this->assertSame(3001,$before['5.1']-$after['5.1']);
        $this->assertSame(3001,$after['1.1.5']-$before['1.1.5']);
        $state=app(InventarioFraccionableService::class)->estado($i->refresh()); $this->assertSame(7001,$state['v']);
        $this->assertSame(40000,$state['q']);
    }

    public function test_evidence_in_use_prevents_migration_down_and_modal_renders_contract(): void
    {
        [$i,$s,$c,$sale]=$this->fixture(true);
        $this->get('/historial-ventas')->assertOk()->assertSee('execution_declaration',false)->assertSee('Constatación retrospectiva');
        $this->post('/ventas/'.$sale->id.'/anular',$this->evidence())->assertSessionHas('success');
        $before=$this->snapshot();
        $migration=require database_path('migrations/2026_11_14_000004_add_sale_annulment_evidence.php');
        try {$migration->down(); $this->fail('In-use evidence must survive');} catch (\RuntimeException $e) {$this->assertStringContainsString('evidencia',$e->getMessage());}
        $this->assertSame($before,$this->snapshot());
    }

    public function test_retained_appointment_approval_excludes_its_own_document_from_duplicate_guard(): void
    {
        $i=$this->itemV2(100); $this->buyV2($i,2,'10'); $s=$this->serviceV2([$i->id=>20]);
        $c=app(AgendaService::class)->reservar(['client_id'=>Cliente::create(['name'=>'H04 retained'])->id,
            'service_id'=>$s->id,'stylists'=>[auth()->id()],'appointment_date'=>'2026-11-20 10:00:00']);
        $line=$this->lineV2($s); $line['appointment_id']=$c->id;
        $id=$this->postJson('/sales',['cart'=>[$line],'requiere_aprobacion'=>true])->assertCreated()->json('sale_id');
        $this->assertSame('pendiente_aprobacion',Venta::findOrFail($id)->status);
        app(AprobacionService::class)->aprobarVenta($id,auth()->id());
        $this->assertSame('pagada',Venta::findOrFail($id)->status); $this->assertSame('completada',$c->fresh()->status);
        $this->assertSame('80.00',$i->fresh()->current_volume);
        $before=$this->snapshot();
        $this->postJson('/sales',['cart'=>[$line]])->assertUnprocessable();
        $this->assertSame($before,$this->snapshot());
    }

    public function test_no_prestation_cannot_carry_an_attendance_date(): void
    {
        [$i,$s,$c,$sale]=$this->fixture(true); $before=$this->snapshot();
        $data=$this->evidence('devolucion_completa'); $data['execution_declaration']='no_prestada'; $data['execution_attended_on']=today()->toDateString();
        $this->post('/ventas/'.$sale->id.'/anular',$data)->assertSessionHasErrors('execution_attended_on');
        $this->assertSame($before,$this->snapshot());
    }

    public function test_complete_zero_capture_cannot_claim_an_empty_return_to_reopen_performed_execution(): void
    {
        $i=$this->itemV2(100); $this->buyV2($i,1,'10'); $s=$this->serviceV2([$i->id=>20]);
        $c=app(AgendaService::class)->reservar(['client_id'=>Cliente::create(['name'=>'H04 zero'])->id,
            'service_id'=>$s->id,'stylists'=>[auth()->id()],'appointment_date'=>'2026-11-20 10:00:00']);
        $line=$this->lineV2($s,[$i->id=>0]); $line['appointment_id']=$c->id;
        $id=$this->postJson('/sales',['cart'=>[$line]])->assertCreated()->json('sale_id');
        $sale=Venta::findOrFail($id); $this->assertSame('complete',$sale->detalles->first()->consumption_snapshot['mode']);
        $this->assertSame(0,DB::table('stock_ledger_entries')->where('referencia_tipo','venta')->where('referencia_id',$id)->count());
        $before=$this->snapshot();
        foreach (['devolucion_completa','correccion_completa'] as $intent) {
            $this->post('/ventas/'.$id.'/anular',$this->evidence($intent))->assertSessionHas('error');
            $this->assertSame($before,$this->snapshot());
        }
        $this->post('/ventas/'.$id.'/anular',$this->evidence())->assertSessionHas('success');
        $this->assertSame('completada',$c->fresh()->status);
        $this->assertNull($sale->fresh()->annulment_evidence['physical_verification']);
        $this->postJson('/sales',['cart'=>[$line]])->assertUnprocessable();
    }
}
