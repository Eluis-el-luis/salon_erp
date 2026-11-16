<?php

namespace Tests\Feature;

use App\Models\{CuentaContable,AsientoContable};
use App\Services\{AccountIntegrityService,ContabilidadService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\InventoryH02Fixtures;
use Tests\TestCase;

class AccountIntegrityTest extends TestCase
{
    use DatabaseTransactions, InventoryH02Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $actor=$this->actorH02();
        $actor->update(['role'=>'contador','role_id'=>\App\Models\Rol::where('name','Contador')->value('id')]);
        $this->actingAs($actor);
    }

    protected function data(array $extra=[]): array
    {
        return array_replace(['codigo'=>'6.92','nombre'=>'AC02 fixture','tipo'=>'gasto','naturaleza'=>'deudora',
            'cuenta_padre_id'=>CuentaContable::where('codigo','6')->value('id'),'permite_movimiento'=>true,'activa'=>true],$extra);
    }

    protected function account(array $extra=[]): CuentaContable
    {
        try {return app(AccountIntegrityService::class)->save($this->data($extra));}
        catch (\Error $e) {throw new \RuntimeException($e->getMessage().' '.basename($e->getFile()).':'.$e->getLine().' '.implode(' / ',array_map(fn ($f)=>basename($f['file']??'').':'.($f['line']??0),$e->getTrace())),0,$e);}
    }

    protected function manual(int $id,string $date): array
    {
        return ['fecha'=>$date,'concepto'=>'AC02 manual fixture','lineas'=>[
            ['cuenta_id'=>$id,'debe'=>'12.50','haber'=>0],
            ['cuenta_id'=>CuentaContable::where('codigo','1.1.1')->value('id'),'debe'=>0,'haber'=>'12.50']]];
    }

    public function test_create_compatible_subaccount_and_corrector(): void
    {
        $this->postJson('/catalogo',$this->data())->assertCreated();
        $this->assertDatabaseHas('cuentas_contables',['codigo'=>'6.92','nivel'=>2]);
        $this->postJson('/catalogo',$this->data(['codigo'=>'4.91','tipo'=>'ingreso','naturaleza'=>'deudora',
            'cuenta_padre_id'=>CuentaContable::where('codigo','4')->value('id')]))->assertCreated();
        $this->postJson('/catalogo',$this->data(['codigo'=>'1.1.1.91','tipo'=>'activo',
            'cuenta_padre_id'=>CuentaContable::where('codigo','1.1.1')->value('id')]))->assertCreated();
        $this->assertTrue(CuentaContable::where('codigo','1.1.1')->first()->permite_movimiento);
    }

    public function test_incompatible_parent_and_cycles_rejected(): void
    {
        try {$this->postJson('/catalogo',$this->data(['cuenta_padre_id'=>CuentaContable::where('codigo','1')->value('id')]))->assertUnprocessable();}
        catch (\Error $e) {throw new \RuntimeException($e->getMessage().' '.basename($e->getFile()).':'.$e->getLine().' '.implode(' / ',array_map(fn ($f)=>basename($f['file']??'').':'.($f['line']??0),$e->getTrace())),0,$e);}
        $a=$this->account(['permite_movimiento'=>false]);
        $b=$this->account(['codigo'=>'6.93','cuenta_padre_id'=>$a->id]);
        $this->putJson('/catalogo/'.$a->id,$this->data(['cuenta_padre_id'=>$a->id]))->assertUnprocessable();
        $this->putJson('/catalogo/'.$a->id,$this->data(['cuenta_padre_id'=>$b->id]))->assertUnprocessable();
        $this->assertSame(2,$a->refresh()->nivel);
    }

    public function test_move_unused_branch_updates_descendant_levels_atomically(): void
    {
        $a=$this->account(['permite_movimiento'=>false]);
        $b=$this->account(['codigo'=>'6.93','cuenta_padre_id'=>$a->id]);
        $this->putJson('/catalogo/'.$a->id,$this->data(['cuenta_padre_id'=>null,'permite_movimiento'=>false]))->assertOk();
        $this->assertSame(1,$a->refresh()->nivel); $this->assertSame(2,$b->refresh()->nivel);
    }

    public function test_history_freezes_structure_but_deactivation_keeps_reports(): void
    {
        $a=$this->account(); $this->post('/asientos',$this->manual($a->id,today()->toDateString()))->assertSessionHasNoErrors();
        $details=DB::table('detalle_asientos')->where('cuenta_id',$a->id)->get()->toJson();
        $beforeReport=$this->get('/contabilidad/resultados?mes=todo')->assertOk()->viewData('utilidadNeta');
        foreach (['codigo'=>'6.94','tipo'=>'costo','naturaleza'=>'acreedora','cuenta_padre_id'=>null,'permite_movimiento'=>false] as $field=>$value) {
            $this->putJson('/catalogo/'.$a->id,$this->data([$field=>$value]))->assertUnprocessable();
        }
        $this->putJson('/catalogo/'.$a->id,$this->data(['activa'=>false]))->assertOk();
        $r=$this->get('/contabilidad/resultados?mes=todo')->assertOk();
        $this->assertNotNull($r->viewData('cuentasResultados')->firstWhere('id',$a->id));
        $this->assertSame($beforeReport,$r->viewData('utilidadNeta'));
        $this->assertSame($details,DB::table('detalle_asientos')->where('cuenta_id',$a->id)->get()->toJson());
        $this->deleteJson('/catalogo/'.$a->id)->assertForbidden();
        $count=AsientoContable::count();
        $this->post('/asientos',$this->manual($a->id,today()->toDateString()))->assertSessionHasErrors();
        $this->assertSame($count,AsientoContable::count());
    }

    public function test_association_dependencies_block_change_deactivation_and_delete(): void
    {
        $a=$this->account();
        $bank=\App\Models\Banco::create(['codigo'=>'AC02','nombre'=>'Banco fixture','activo'=>true]);
        $bankAccount=\App\Models\CuentaBancaria::create(['banco_id'=>$bank->id,'numero_cuenta'=>'AC02-FIXTURE','tipo_cuenta'=>'Corriente',
            'moneda_id'=>DB::table('monedas')->where('codigo','NIO')->value('id'),'cuenta_contable_id'=>$a->id,'saldo_actual'=>0,'activa'=>true]);
        $salary=DB::table('salary_structures')->value('id');$this->assertNotNull($salary,'Esquema HRMS debe existir tras las migraciones.');
        $salaryLine=DB::table('salary_structure_details')->insertGetId(['salary_structure_id'=>$salary,'codigo'=>'AC02','nombre'=>'Fixture salarial',
            'tipo'=>'ingreso','calculadora'=>'fijo','valor'=>0,'orden'=>99,'cuenta_contable_id'=>$a->id,'activo'=>true]);
        $expense=DB::table('tipos_gasto')->insertGetId(['codigo'=>'AC02','nombre'=>'Fixture gasto','cuenta_contable_id'=>$a->id]);
        foreach (['tipos_gasto'=>$expense,'salary_structure_details'=>$salaryLine,'cuentas_bancarias'=>$bankAccount->id] as $table=>$id) {
            $this->assertContains($table,app(AccountIntegrityService::class)->dependencies($a->id));
            $this->putJson('/catalogo/'.$a->id,$this->data(['activa'=>false]))->assertUnprocessable();
        }
        $this->putJson('/catalogo/'.$a->id,$this->data(['codigo'=>'6.99']))->assertUnprocessable();
        $this->putJson('/catalogo/'.$a->id,$this->data(['activa'=>false]))->assertUnprocessable();
        $this->deleteJson('/catalogo/'.$a->id)->assertForbidden();
    }

    public function test_system_account_still_changes_only_name(): void
    {
        $a=CuentaContable::where('codigo','4.1')->first();
        $this->putJson('/catalogo/'.$a->id,['nombre'=>'AC02 nombre','tipo'=>'gasto','activa'=>false])->assertOk();
        $this->assertSame('ingreso',$a->refresh()->tipo);$this->assertTrue($a->activa);
        $this->deleteJson('/catalogo/'.$a->id)->assertForbidden();
    }

    public function test_manual_uses_date_period_and_closed_period_rolls_back(): void
    {
        $a=$this->account();
        $this->post('/asientos',$this->manual($a->id,'2031-04-05'))->assertSessionHasNoErrors();
        $entry=AsientoContable::where('modulo_origen','manual')->orderByDesc('id')->firstOrFail();
        $this->assertSame('2031-04-01',DB::table('periodos_contables')->where('id',$entry->periodo_id)->value('fecha_inicio'));
        DB::table('periodos_contables')->where('id',$entry->periodo_id)->update(['estado'=>'cerrado']);
        $before=AsientoContable::count();
        $this->post('/asientos',$this->manual($a->id,'2031-04-05'))->assertSessionHasErrors();
        $this->assertSame($before,AsientoContable::count());
    }

    public function test_automatic_cannot_be_reversed_from_manual_route(): void
    {
        $a=$this->account();$this->post('/asientos',$this->manual($a->id,today()->toDateString()))->assertSessionHasNoErrors();
        $entry=AsientoContable::where('modulo_origen','manual')->orderByDesc('id')->first();
        foreach (['ventas','compras','inventario','nomina','caja_chica'] as $module) {
            DB::table('asientos_contables')->where('id',$entry->id)->update(['modulo_origen'=>$module]);
            $before=AsientoContable::count();
            $this->post('/asientos/'.$entry->id.'/reversar')->assertSessionHasErrors();
            $this->assertSame($before,AsientoContable::count());
        }
    }

    public function test_manual_reverse_inactive_original_is_exact_and_not_repeated(): void
    {
        $a=$this->account();$this->post('/asientos',$this->manual($a->id,today()->toDateString()))->assertSessionHasNoErrors();
        $entry=AsientoContable::where('modulo_origen','manual')->orderByDesc('id')->first();
        $this->putJson('/catalogo/'.$a->id,$this->data(['activa'=>false]))->assertOk();
        $this->post('/asientos/'.$entry->id.'/reversar')->assertSessionHasNoErrors();
        $r=AsientoContable::where('modulo_origen','reversion')->where('referencia_id',$entry->id)->firstOrFail();
        $this->assertSame('12.50',DB::table('detalle_asientos')->where('asiento_id',$r->id)->where('cuenta_id',$a->id)->value('haber'));
        $before=AsientoContable::count();$this->post('/asientos/'.$entry->id.'/reversar')->assertSessionHasErrors();
        $this->assertSame($before,AsientoContable::count());
    }

    public function test_non_imputable_account_and_midway_failure_leave_no_document(): void
    {
        $a=$this->account(['permite_movimiento'=>false]);$before=AsientoContable::count();$details=DB::table('detalle_asientos')->count();
        $this->post('/asientos',$this->manual($a->id,today()->toDateString()))->assertSessionHasErrors();
        $this->assertSame($before,AsientoContable::count());
        $this->assertSame($details,DB::table('detalle_asientos')->count());
        try {
            app(AccountIntegrityService::class)->catalogue(function () { $this->account(['codigo'=>'6.93']);throw new \RuntimeException('AC02 injected'); });
        } catch (\RuntimeException $e) {$this->assertSame('AC02 injected',$e->getMessage());}
        $this->assertSame(1,CuentaContable::where('codigo','6.92')->count());
        $this->assertSame(0,CuentaContable::where('codigo','6.93')->count());
    }

    public function test_purchase_origin_can_reverse_its_exact_inactive_account(): void
    {
        $item=$this->stockH02(2);
        $invoice=\App\Models\FacturaCompra::orderByDesc('id')->firstOrFail();
        $original=AsientoContable::where('modulo_origen','compras')->where('referencia_id',$invoice->id)->firstOrFail();
        $asset=CuentaContable::where('codigo','1.1.4')->firstOrFail();
        // Estado histórico hipotético solo dentro del rollback de test, no una desactivación permitida por UI de sistema.
        DB::table('cuentas_contables')->where('id',$asset->id)->update(['activa'=>false]);
        app(\App\Services\CompraService::class)->anularFactura($invoice->id,'AC02 reversa exacta',auth()->id());
        $reverse=AsientoContable::where('numero_asiento','like','REINV-COM-%')->where('referencia_id',$invoice->id)->firstOrFail();
        $this->assertSame(DB::table('detalle_asientos')->where('asiento_id',$original->id)->where('cuenta_id',$asset->id)->value('debe'),
            DB::table('detalle_asientos')->where('asiento_id',$reverse->id)->where('cuenta_id',$asset->id)->value('haber'));
        $this->assertSame(0,$item->refresh()->existencia_actual);
    }

    public function test_gasto_rejects_asset_as_expense_and_rolls_back(): void
    {
        $before=AsientoContable::count();
        $this->post('/contabilidad/gasto',['descripcion'=>'AC02 invalid expense','monto'=>'10','metodo_pago'=>'efectivo',
            'cuenta_id'=>CuentaContable::where('codigo','1.1.1')->value('id')])->assertSessionHasErrors();
        $this->assertSame($before,AsientoContable::count());
    }

    public function test_close_keeps_results_and_rejects_new_entries_in_closed_period(): void
    {
        $a=$this->account();
        $this->post('/asientos',$this->manual($a->id,'2025-01-15'))->assertSessionHasNoErrors();
        $p=DB::table('periodos_contables')->where('fecha_inicio','2025-01-01')->first();
        $this->assertNotNull($p);
        $before=$this->get('/contabilidad/resultados?mes=2025-01')->assertOk()->viewData('utilidadNeta');
        $this->post('/periodos/'.$p->id.'/cierre')->assertSessionHasNoErrors();
        $this->assertSame('cerrado',DB::table('periodos_contables')->where('id',$p->id)->value('estado'));
        $this->assertSame($before,$this->get('/contabilidad/resultados?mes=2025-01')->assertOk()->viewData('utilidadNeta'));
        $count=AsientoContable::count();
        $this->post('/asientos',$this->manual($a->id,'2025-01-16'))->assertSessionHasErrors();
        $this->assertSame($count,AsientoContable::count());
    }

    public function test_parent_of_used_child_cannot_be_reparented_or_renumbered(): void
    {
        $parent=$this->account(['codigo'=>'6.91','permite_movimiento'=>false]);
        $child=$this->account(['cuenta_padre_id'=>$parent->id]);
        $this->post('/asientos',$this->manual($child->id,today()->toDateString()))->assertSessionHasNoErrors();
        $this->putJson('/catalogo/'.$parent->id,$this->data(['codigo'=>'6.91','permite_movimiento'=>false,'cuenta_padre_id'=>null]))->assertUnprocessable();
        $this->putJson('/catalogo/'.$parent->id,$this->data(['codigo'=>'6.93','permite_movimiento'=>false]))->assertUnprocessable();
        $this->assertSame(2,$parent->refresh()->nivel);$this->assertSame(3,$child->refresh()->nivel);
        $this->assertSame('6.91',$parent->codigo);
    }
}
