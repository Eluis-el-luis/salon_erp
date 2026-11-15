<?php

namespace Tests\Feature;

use App\Models\{Articulo, Usuario};
use App\Services\InventarioFraccionableService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OpeningDocumentFixtures;
use Tests\TestCase;

class OpeningDocumentSchemaTest extends TestCase
{
    use DatabaseTransactions, OpeningDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp(); $this->documentaryWritesOnly();
    }

    public function test_types_pending_policies_and_null_values_have_no_financial_defaults(): void
    {
        $cols=DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA',DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME',['inventory_openings','inventory_opening_lines'])->get()->keyBy(fn($r)=>$r->TABLE_NAME.'.'.$r->COLUMN_NAME);
        foreach (['inventory_openings.total_value_nio'=>'decimal(15,2)','inventory_opening_lines.document_value_nio'=>'decimal(15,2)',
            'inventory_opening_lines.informational_unit_cost'=>'decimal(15,6)','inventory_opening_lines.counted_units'=>'decimal(12,4)',
            'inventory_opening_lines.counted_remainder'=>'decimal(8,2)','inventory_opening_lines.container_capacity'=>'decimal(8,2)',
            'inventory_opening_lines.native_quantity'=>'decimal(15,2)'] as $key=>$type) $this->assertSame($type,$cols[$key]->COLUMN_TYPE);
        foreach (['counterpart_account_id','policy_snapshot','total_value_nio','effective_at','counted_by','supervised_by','result_asiento_id'] as $field) {
            $this->assertSame('YES',$cols['inventory_openings.'.$field]->IS_NULLABLE);
            $this->assertNull($cols['inventory_openings.'.$field]->COLUMN_DEFAULT);
        }
        $id=$this->opening(); $row=DB::table('inventory_openings')->find($id);
        $this->assertNull($row->policy_snapshot); $this->assertNull($row->counterpart_account_id); $this->assertNull($row->total_value_nio);
        $this->rejectedOpeningSql(fn()=>DB::table('inventory_openings')->where('id',$id)->update(['status'=>'confirmed']),3819);
    }

    public function test_uuid_unique_but_hash_is_not_a_fake_idempotent_writer(): void
    {
        $id=$this->opening(); $row=DB::table('inventory_openings')->find($id);
        $this->rejectedOpeningSql(fn()=>$this->opening(['operation_uuid'=>$row->operation_uuid]),1062);
        $second=$this->opening(['content_hash'=>$row->content_hash]);
        $this->assertNotSame($id,$second); // UNIQUE/hash almacenado no demuestran idempotencia de stock.
    }

    public function test_multiple_positions_same_article_and_whole_open_container_are_representable(): void
    {
        $i=DB::table('items')->where('inventory_representation_version',2)->orderBy('id')->first();
        $this->assertNotNull($i);
        $h=$this->opening();
        $closed=$this->lineData($h,['item_id'=>$i->id,'item_type'=>$i->type,'physical_kind'=>'closed','inventory_representation_version'=>2,
            'native_unit'=>'ml','container_capacity'=>'100.00','counted_units'=>'2.0000','native_quantity'=>'200.00']);
        DB::table('inventory_opening_lines')->insert($closed);
        $open=array_replace($closed,['position'=>2,'physical_kind'=>'open','counted_units'=>null,'counted_remainder'=>'60.00','native_quantity'=>'60.00']);
        DB::table('inventory_opening_lines')->insert($open);
        $this->assertSame(2,DB::table('inventory_opening_lines')->where('opening_id',$h)->count());
        $this->assertSame('260.00',DB::table('inventory_opening_lines')->where('opening_id',$h)->sum('native_quantity'));
        $this->rejectedOpeningSql(fn()=>DB::table('inventory_opening_lines')->insert($open),1062);
        DB::table('inventory_opening_lines')->insert(array_replace($open,['position'=>3,'counted_remainder'=>'100.00','native_quantity'=>'100.00']));
        $this->assertSame('open',DB::table('inventory_opening_lines')->where('opening_id',$h)->where('position',3)->value('physical_kind'));
        // Dos propuestas abiertas se almacenan como borrador; la elegibilidad agregada
        // y el único abierto físico siguen siendo controles del futuro escritor/B1.
    }

    public function test_foreign_keys_reject_missing_and_cross_article_results(): void
    {
        foreach (['executed_by','counted_by','supervised_by','counterpart_account_id','result_asiento_id'] as $key)
            $this->rejectedOpeningSql(fn()=>$this->opening([$key=>999999999]),1452);
        $h=$this->opening(); $p=$this->resultParents();
        foreach (['opening_id','item_id','result_lot_id','result_ledger_id'] as $key)
            $this->rejectedOpeningSql(fn()=>DB::table('inventory_opening_lines')->insert($this->lineData($h,[$key=>999999999])),1452);
        $other=DB::table('items')->where('id','<>',$p->item)->orderBy('id')->value('id'); $this->assertNotNull($other);
        foreach (['result_lot_id'=>$p->lot,'result_ledger_id'=>$p->ledger] as $key=>$value)
            $this->rejectedOpeningSql(fn()=>DB::table('inventory_opening_lines')->insert($this->lineData($h,['item_id'=>$other,$key=>$value])),1452);
        DB::table('inventory_opening_lines')->insert($this->lineData($h,['result_lot_id'=>$p->lot,'result_ledger_id'=>$p->ledger]));
        $h2=$this->opening();
        foreach (['result_lot_id'=>$p->lot,'result_ledger_id'=>$p->ledger] as $key=>$value)
            $this->rejectedOpeningSql(fn()=>DB::table('inventory_opening_lines')->insert($this->lineData($h2,[$key=>$value])),1062);
    }

    public function test_restrict_definitions_and_no_document_cascade(): void
    {
        $fks=DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA',DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME',['inventory_openings','inventory_opening_lines'])->get();
        $this->assertCount(9,$fks);
        foreach ($fks as $fk) {$this->assertSame('RESTRICT',$fk->DELETE_RULE); $this->assertSame('RESTRICT',$fk->UPDATE_RULE);}
        $h=$this->opening(); DB::table('inventory_opening_lines')->insert($this->lineData($h));
        $this->rejectedOpeningSql(fn()=>DB::table('inventory_openings')->where('id',$h)->delete(),1451);
        $this->rejectedOpeningSql(fn()=>DB::table('inventory_openings')->where('id',$h)->update(['id'=>0]),1451);
        $this->assertSame(1,DB::table('inventory_opening_lines')->where('opening_id',$h)->count());
    }

    public static function badIdentities(): array
    {
        return [['operation_uuid','not-a-uuid'],['content_hash',str_repeat('g',64)],['document_reference',' '],['contract_version',2],['status','other'],
            ['document_evidence','{}'],['document_evidence','[]'],['document_evidence','{"version":"1"}'],['policy_snapshot','{"version":2}']];
    }

    #[DataProvider('badIdentities')]
    public function test_identity_and_versioned_json_checks(string $field,mixed $value): void
    {
        $this->rejectedOpeningSql(fn()=>$this->opening([$field=>$value]),3819);
    }

    public static function badQuantities(): array
    {
        return [
            [['counted_units'=>'1.5000','native_quantity'=>'1.50']], [['counted_units'=>'0','native_quantity'=>'0']],
            [['native_quantity'=>'-1']], [['item_type'=>'servicio']], [['physical_kind'=>'other']],
            [['document_value_nio'=>'-0.01']], [['informational_unit_cost'=>'-0.000001']],
            [['physical_kind'=>'open','inventory_representation_version'=>2,'native_unit'=>'ml','container_capacity'=>'100',
                'counted_units'=>null,'counted_remainder'=>'100.01','native_quantity'=>'100.01']],
            [['physical_kind'=>'closed','inventory_representation_version'=>2,'native_unit'=>'ml','container_capacity'=>'100',
                'counted_units'=>'1','native_quantity'=>'99.99']],
            [['physical_kind'=>'closed','inventory_representation_version'=>2,'native_unit'=>'ml','container_capacity'=>'100000',
                'counted_units'=>'1000000','native_quantity'=>'100000000000']],
            [['physical_evidence'=>'{"version":null}']], [['valuation_evidence'=>'{}']],
        ];
    }

    #[DataProvider('badQuantities')]
    public function test_physical_value_and_evidence_constraints(array $extra): void
    {
        $h=$this->opening();
        $this->rejectedOpeningSql(fn()=>DB::table('inventory_opening_lines')->insert($this->lineData($h,$extra)),3819);
    }

    public function test_exact_cent_and_six_decimal_storage_without_emitting_stock(): void
    {
        $h=$this->opening();
        $data=$this->lineData($h,['physical_kind'=>'open','inventory_representation_version'=>2,'native_unit'=>'oz',
            'counted_units'=>null,'container_capacity'=>'10.00','counted_remainder'=>'0.01','native_quantity'=>'0.01',
            'document_value_nio'=>'17.23','informational_unit_cost'=>'1.234567']);
        $id=DB::table('inventory_opening_lines')->insertGetId($data); $row=DB::table('inventory_opening_lines')->find($id);
        $this->assertSame('0.01',$row->counted_remainder); $this->assertSame('17.23',$row->document_value_nio);
        $this->assertSame('1.234567',$row->informational_unit_cost); $this->assertSame('oz',$row->native_unit);
    }

    public function test_h01a_and_existing_v2_readers_remain_compatible_without_writing_old_tables(): void
    {
        $actor=Usuario::where('is_active',true)->whereIn('role',['alvaro','admin'])->firstOrFail(); $this->actingAs($actor);
        $i=Articulo::where('inventory_representation_version',2)->firstOrFail();
        $before=$i->getAttributes();
        $this->postJson('/inventario',['producto'=>'Schema forbidden stock','type'=>'producto_venta','precio_c'=>100,
            'existencia_actual'=>1,'current_volume'=>0])->assertUnprocessable()->assertJsonValidationErrors(['existencia_actual','current_volume']);
        $this->putJson('/inventario/'.$i->id,['producto'=>'Schema forbidden stock','precio_c'=>100,'existencia_actual'=>2])
            ->assertUnprocessable()->assertJsonValidationErrors('existencia_actual');
        config(['salon.inventory_fractional_v2'=>true]);
        $s=app(InventarioFraccionableService::class)->estado($i);
        $this->assertGreaterThanOrEqual(0,$s['q']);
        $this->assertSame($before,$i->fresh()->getAttributes());
        $this->assertTrue($i->costoUnitarioActual()===null || $i->costoUnitarioActual()>=0);
        $routes=collect(app('router')->getRoutes()->getRoutes())->filter(fn($r)=>str_contains($r->getActionName(),'registrarApertura'));
        $this->assertCount(1,$routes);
        $this->assertSame('inventario/kardex/apertura',$routes->first()->uri());
    }
}
