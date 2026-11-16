<?php

namespace Tests\Unit;

use App\Services\OpeningDocumentIdentity;
use PHPUnit\Framework\TestCase;
use Tests\Support\OpeningContractHypotheses;

final class OpeningIdentityTest extends TestCase
{
    use OpeningContractHypotheses;

    public function test_same_key_same_content_prepared_is_not_success(): void
    {
        $c=$this->prepare(); $s=$c+['status'=>'prepared'];
        self::assertSame('prepared_not_success',(new OpeningDocumentIdentity)->classify($c,$s,false));
    }

    public function test_same_key_modified_content_is_conflict(): void
    {
        $c=$this->prepare(); $s=$c; $s['content_hash']=str_repeat('a',64);
        self::assertSame('same_key_content_conflict',(new OpeningDocumentIdentity)->classify($c,$s,true));
    }

    public function test_other_key_with_history_does_not_reopen_empty_article(): void
    {
        $c=$this->prepare(); $s=$c; $s['operation_uuid']='a01b2b10-0000-4000-8000-000000000002';
        self::assertSame('new_key_history_conflict',(new OpeningDocumentIdentity)->classify($c,$s,true));
        self::assertSame('new_key_requires_eligibility',(new OpeningDocumentIdentity)->classify($c,null,false));
    }

    public function test_confirmed_is_only_an_in_memory_interpretation_never_emitted(): void
    {
        $c=$this->prepare();
        // Documento hipotético, sin INSERT ni efectos reales: no acredita conciliación financiera.
        $s=$c+['status'=>'confirmed','contract_version'=>1,'result_asiento_id'=>91,'total_value_nio'=>'8.00',
            'lines'=>[['position'=>1,'item_id'=>1,'result_lot_id'=>71,'result_ledger_id'=>81]]];
        self::assertSame('same_key_confirmed_candidate_requires_locked_verification',(new OpeningDocumentIdentity)->classify($c,$s,true));
        unset($s['result_asiento_id']);
        self::assertSame('confirmed_incomplete',(new OpeningDocumentIdentity)->classify($c,$s,true));
        $s['result_asiento_id']=91; $s['content']['total_value_nio']='9.00';
        self::assertSame('confirmed_incomplete',(new OpeningDocumentIdentity)->classify($c,$s,true));
    }
}
