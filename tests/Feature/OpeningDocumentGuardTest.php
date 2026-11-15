<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\OpeningDocumentFixtures;
use Tests\TestCase;

class OpeningDocumentGuardTest extends TestCase
{
    use OpeningDocumentFixtures;

    public function test_committed_prepared_document_blocks_down_before_any_ddl(): void
    {
        $this->documentaryWritesOnly();
        $this->assertSame(0,DB::transactionLevel());
        $uuid='a01b2a00-0000-4000-8000-000000000001';
        $row=DB::table('inventory_openings')->where('operation_uuid',$uuid)->first();
        if ($row) {
            $this->assertSame('prepared',$row->status);
            $this->assertSame('H01-B2A STRUCTURAL FIXTURE ONLY',$row->document_reference);
            $this->assertNull($row->result_asiento_id);
        } else {
            $id=$this->opening(['operation_uuid'=>$uuid,'document_reference'=>'H01-B2A STRUCTURAL FIXTURE ONLY']);
            $row=DB::table('inventory_openings')->find($id);
        }
        $this->assertNull($row->counterpart_account_id); $this->assertNull($row->policy_snapshot); $this->assertNull($row->total_value_nio);
        $before=DB::table('inventory_openings')->orderBy('id')->get()->toJson();
        DB::connection()->beforeExecuting(function ($sql) {
            if (!preg_match('/^\s*(select|show)\b/i',$sql)) throw new \LogicException('DDL/escritura prohibidos durante ensayo de guardia.');
        });
        $migration=require database_path('migrations/2026_11_14_000005_create_inventory_opening_documents.php');
        try {$migration->down(); $this->fail('No puede eliminar evidencia preparada.');}
        catch (\RuntimeException $e) {$this->assertStringContainsString('evidencia en uso',$e->getMessage());}
        $this->assertTrue(Schema::hasTable('inventory_openings')); $this->assertTrue(Schema::hasTable('inventory_opening_lines'));
        $this->assertSame($before,DB::table('inventory_openings')->orderBy('id')->get()->toJson());
    }
}
