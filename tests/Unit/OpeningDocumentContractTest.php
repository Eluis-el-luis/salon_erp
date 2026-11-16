<?php

namespace Tests\Unit;

use App\Services\OpeningDocumentContract;
use App\Exceptions\OpeningDocumentValidationException as ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OpeningContractHypotheses;

final class OpeningDocumentContractTest extends TestCase
{
    use OpeningContractHypotheses;

    public function test_valid_header_and_full_open_remain_non_executable(): void
    {
        $input=$this->input(); $input['lines'][0]['counted_remainder']='100.00';
        $r=$this->prepare($input);
        self::assertFalse($r['confirmation_allowed']);
        self::assertSame('open',$r['content']['lines'][0]['physical_kind']);
        self::assertNull($r['content']['lines'][0]['counted_units']);
        self::assertSame('100.00',$r['content']['lines'][0]['native_quantity']);
        self::assertSame(hash('sha256',$r['canonical_json']),$r['content_hash']);
        self::assertSame(7,$r['content']['executed_by']);
        self::assertSame('2026-11-15T06:00:00Z',$r['content']['effective_at']);
    }

    public static function invalidHeaders(): array
    {
        return [['document_reference',''],['operation_uuid','bad'],['count_declaration',false],['counted_at','2026-02-30T00:00:00-06:00'],
            ['effective_at','2026-11-15'],['effective_at','2026-11-16T00:00:00-06:00'],['total_value_nio','8.001'],
            ['total_value_nio','9.00'],['total_value_nio',8.0],['lines',[]],['lines','bad'],['document_evidence',['version'=>'1']],
            ['supervised_by',1],['status','confirmed'],['content_hash',str_repeat('a',64)],['policy',['approvals'=>true]],['executed_by',7]];
    }

    #[DataProvider('invalidHeaders')]
    public function test_invalid_headers_and_authoritative_fields_are_rejected(string $key,$value): void
    {
        $input=$this->input(); $input[$key]=$value;
        $this->expectException(ValidationException::class); $this->prepare($input);
    }

    public static function invalidLines(): array
    {
        return [['counted_remainder',''],['counted_remainder','-1'],['counted_remainder','80.001'],['counted_remainder','0'],
            ['counted_remainder','101'],['counted_remainder','1e1'],['counted_remainder',INF],['counted_remainder',false],
            ['counted_units','1'],['container_capacity','99'],['native_unit','gr'],['document_value_nio','0'],
            ['document_value_nio','-1'],['document_value_nio','99999999999999'],['valuation_source','retail_price'],
            ['valuation_source',null],['container_capacity','0'],['valuation_evidence',null],['physical_evidence',['version'=>2]],['physical_evidence',['version'=>1,'reference'=>'x','description'=>'y','supervisor_id'=>9]],
            ['expiry_status','unknown'],['expires_on','2030-01-01'],['result_lot_id',1],['inventory_representation_version',2],
            ['position',0],['item_id',999],['manufacturer_lot','']];
    }

    #[DataProvider('invalidLines')]
    public function test_invalid_line_is_rejected_before_any_io(string $key,$value): void
    {
        $input=$this->input(); $input['lines'][0][$key]=$value;
        $this->expectException(ValidationException::class); $this->prepare($input);
    }

    public function test_missing_policies_reports_all_pending_approvals(): void
    {
        $c=new OpeningDocumentContract;
        self::assertContains('P1: aprobación pendiente',$c->policyProblems(null));
        self::assertContains('P9: aprobación pendiente',$c->policyProblems(null));
        $this->expectException(ValidationException::class); $c->prepare($this->input(),$this->items(),null,7);
    }

    public static function absentPolicies(): array
    {
        return [['counterpart_code',null],['valuation_sources',[]],['cutoff',null],['zero_cost',null],
            ['unknown_lot',null],['classify_item_ids',null],['legacy_contained',false],['expiry_applicability',[]],
            ['accounting_basis',null],['timezone','invalid'],['counterpart',['code'=>'3.2','type'=>'patrimonio']],
            ['classify_item_ids',[]],['distinct_supervisor',null]];
    }

    #[DataProvider('absentPolicies')]
    public function test_pending_or_contradictory_policy_rejects(string $key,$value): void
    {
        $p=$this->policy(); $p[$key]=$value;
        $this->expectException(ValidationException::class);
        (new OpeningDocumentContract)->prepare($this->input(),$this->items(),$p,7);
    }

    public function test_multiple_positions_different_values_are_not_pooled_or_resealed(): void
    {
        $i=$this->input(); $i['lines'][]=$this->line(['position'=>2,'physical_kind'=>'closed','counted_units'=>'2.0000',
            'counted_remainder'=>null,'document_value_nio'=>'30.00']); $i['total_value_nio']='38.00';
        $r=$this->prepare($i);
        self::assertSame(['8.00','30.00'],array_column($r['content']['lines'],'document_value_nio'));
        self::assertSame(['80.00','200.00'],array_column($r['content']['lines'],'native_quantity'));
        self::assertNull($r['content']['lines'][0]['counted_units']);
        self::assertSame(2,$r['content']['lines'][1]['counted_units']);
    }

    public static function duplicateCases(): array { return [['position'],['open'],['observation']]; }

    #[DataProvider('duplicateCases')]
    public function test_duplicate_positions_opens_and_observations_are_rejected(string $case): void
    {
        $i=$this->input();
        if ($case==='observation') $i['lines'][0]=$this->line(['physical_kind'=>'closed','counted_units'=>'1','counted_remainder'=>null]);
        $i['lines'][]=$i['lines'][0];
        if ($case!=='position') $i['lines'][1]['position']=2;
        $i['total_value_nio']='16.00';
        $this->expectException(ValidationException::class); $this->prepare($i);
    }

    public function test_units_are_integers_with_no_fictitious_capacity(): void
    {
        $i=$this->input(); $i['lines'][0]=$this->line(['item_id'=>2,'physical_kind'=>'units','native_unit'=>'und',
            'container_capacity'=>null,'counted_units'=>'2.0','counted_remainder'=>null,'expiry_status'=>'known','expires_on'=>'2027-01-01']);
        self::assertSame('2.00',$this->prepare($i)['content']['lines'][0]['native_quantity']);
        $i['lines'][0]['counted_units']='2.5';
        $this->expectException(ValidationException::class); $this->prepare($i);
    }

    public function test_units_and_physical_accumulation_limits(): void
    {
        $i=$this->input(); $items=$this->items(); $items[1]['total_volume']='999999.99';
        $i['lines'][0]=$this->line(['physical_kind'=>'closed','container_capacity'=>'999999.99','counted_units'=>'99999999','counted_remainder'=>null]);
        $this->expectException(ValidationException::class);
        (new OpeningDocumentContract)->prepare($i,$items,$this->policy(),7);
    }

    public function test_catalogue_representation_and_archive_are_not_client_decisions(): void
    {
        $items=$this->items(); $items[1]['inventory_representation_version']=1;
        $this->expectException(ValidationException::class);
        (new OpeningDocumentContract)->prepare($this->input(),$items,$this->policy(),7);
    }

    public function test_hash_stable_for_key_line_decimal_uuid_and_instant_formats(): void
    {
        $a=$this->input(); $a['lines'][]=$this->line(['position'=>2,'physical_kind'=>'closed','counted_units'=>'1','counted_remainder'=>null]);
        $a['total_value_nio']='16.00'; $b=$a;
        $b['operation_uuid']=strtoupper($b['operation_uuid']); $b['total_value_nio']='16';
        $b['effective_at']='2026-11-15T06:00:00Z'; $b['counted_at']=$b['effective_at'];
        $b['lines'][0]['counted_remainder']='080.0'; $b['lines']=array_reverse($b['lines']); $b=array_reverse($b,true);
        self::assertSame($this->prepare($a)['content_hash'],$this->prepare($b)['content_hash']);
        self::assertSame($a['operation_uuid'],$this->prepare($b)['operation_uuid']);
    }

    public function test_meaningful_change_changes_hash_uuid_is_not_content(): void
    {
        $a=$this->input(); $b=$a; $b['lines'][0]['physical_evidence']['description']='Otra constatación';
        self::assertNotSame($this->prepare($a)['content_hash'],$this->prepare($b)['content_hash']);
        $b=$a; $b['operation_uuid']='a01b2b10-0000-4000-8000-000000000002';
        self::assertSame($this->prepare($a)['content_hash'],$this->prepare($b)['content_hash']);
    }

    public function test_no_confirmation_even_with_hypothetically_approved_policies(): void
    {
        self::assertFalse($this->prepare()['confirmation_allowed']);
        $this->expectException(\LogicException::class);
        (new OpeningDocumentContract)->assertConfirmationUnavailable();
    }

    public function test_memory_validation_does_not_change_catalogue_or_activate_a_policy(): void
    {
        $input=$this->input(); $items=$this->items(); $p=$this->policy();
        $before=[$input,$items,$p];
        $c=new OpeningDocumentContract; $r=$c->prepare($input,$items,$p,7);
        self::assertSame($before,[$input,$items,$p]);
        self::assertNull($items[1]['inventory_representation_version']);
        self::assertFalse($r['confirmation_allowed']);
        self::assertContains('P1: aprobación pendiente',$c->policyProblems(null));
    }
}
