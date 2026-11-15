<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fixtures exclusivamente documentales: no crea ni altera padres históricos. */
trait OpeningDocumentFixtures
{
    protected function documentaryWritesOnly(): void
    {
        DB::connection()->beforeExecuting(function ($sql) {
            if (preg_match('/^\s*(select|show)\b/i',$sql)) return;
            if (preg_match('/^\s*(?:insert into|update|delete from) `(inventory_openings|inventory_opening_lines)`(?:\s|\()/i',$sql)) return;
            throw new \LogicException('H01-B2A: SQL fuera de las dos tablas documentales.');
        });
    }

    protected function openingData(array $extra=[]): array
    {
        $actor=DB::table('users')->orderBy('id')->value('id');
        if (!$actor) throw new \RuntimeException('Se requieren padres existentes para probar FKs sin crearlos.');
        return array_replace(['operation_uuid'=>(string)Str::uuid(),'content_hash'=>hash('sha256','schema-fixture-v1'),
            'contract_version'=>1,'status'=>'prepared','document_reference'=>'SCHEMA-FIXTURE-'.Str::uuid(),
            'recorded_at'=>now()->format('Y-m-d H:i:s'),'executed_by'=>$actor,
            'document_evidence'=>json_encode(['version'=>1,'purpose'=>'structural_fixture_not_authorized_to_post'])],$extra);
    }

    protected function opening(array $extra=[]): int
    {
        return DB::table('inventory_openings')->insertGetId($this->openingData($extra));
    }

    protected function resultParents(): object
    {
        $row=DB::table('lotes as l')->join('stock_ledger_entries as e','e.item_id','=','l.item_id')
            ->join('items as i','i.id','=','l.item_id')->where('i.type','<>','servicio')
            ->orderBy('l.id')->orderBy('e.id')->first(['l.id as lot','e.id as ledger','i.id as item','i.type','i.unit_measure']);
        if (!$row) throw new \RuntimeException('Faltan lotes/movimientos existentes; no se inventan fixtures de stock.');
        return $row;
    }

    protected function lineData(int $opening, array $extra=[]): array
    {
        $parent=$this->resultParents();
        return array_replace(['opening_id'=>$opening,'position'=>1,'item_id'=>$parent->item,'physical_kind'=>'units',
            'item_type'=>$parent->type,'inventory_representation_version'=>null,'native_unit'=>'und','counted_units'=>'1.0000',
            'counted_remainder'=>null,'container_capacity'=>null,'native_quantity'=>'1.00',
            'physical_evidence'=>json_encode(['version'=>1,'purpose'=>'structural_fixture_not_authorized_to_post'])],$extra);
    }

    protected function rejectedOpeningSql(callable $action, int $code): void
    {
        try {DB::transaction($action); $this->fail('SQL debía rechazar el fixture.');}
        catch (\Illuminate\Database\QueryException $e) {$this->assertSame($code,$e->errorInfo[1]);}
    }
}
