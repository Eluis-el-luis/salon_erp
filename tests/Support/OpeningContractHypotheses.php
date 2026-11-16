<?php

namespace Tests\Support;

/** Hipótesis SOLO en memoria, no decisiones aprobadas ni configuración empresarial. */
trait OpeningContractHypotheses
{
    private function policy(): array
    {
        return ['version'=>1,'reference'=>'HYPOTHESIS-NOT-AUTHORIZED','approvals'=>array_fill_keys(array_map(fn ($n)=>'P'.$n,range(1,9)),true),
            'counterpart_code'=>'TEST-ONLY-NOT-REAL','accounting_basis'=>'authorized_opening',
            'counterpart'=>['code'=>'TEST-ONLY-NOT-REAL','type'=>'patrimonio','nature'=>'acreedora','active'=>true,'allows_entries'=>true],
            'cutoff'=>'2026-11-15T00:00:00-06:00','timezone'=>'America/Mexico_City','valuation_sources'=>['verified_act'],
            'zero_cost'=>'reject','unknown_lot'=>'internal_reference','expiry'=>'reject_unknown_or_expired',
            'expiry_applicability'=>[1=>false,2=>true], 'classify_item_ids'=>[1], 'counter_declares'=>true,
            'distinct_supervisor'=>true,'legacy_contained'=>true];
    }

    private function items(): array
    {
        return [1=>['id'=>1,'type'=>'producto_interno','is_fractionable'=>true,'unit_measure'=>'ml','total_volume'=>'100.00',
            'inventory_representation_version'=>null,'existencia_actual'=>'0','current_volume'=>'0.00','costo_promedio'=>'0.000000'],
            2=>['id'=>2,'type'=>'producto_venta','is_fractionable'=>false,'unit_measure'=>'und','total_volume'=>'0.00',
            'inventory_representation_version'=>null,'existencia_actual'=>'0','current_volume'=>'0.00','costo_promedio'=>'0.000000']];
    }

    private function line(array $extra = []): array
    {
        return array_replace(['position'=>1,'item_id'=>1,'physical_kind'=>'open','native_unit'=>'ml','container_capacity'=>'100.00',
            'counted_units'=>null,'counted_remainder'=>'80.00','manufacturer_lot'=>null,'expiry_status'=>'not_applicable','expires_on'=>null,
            'physical_evidence'=>['version'=>1,'reference'=>'COUNT-POS-1','description'=>'Fixture de conteo, no existe material real'],
            'document_value_nio'=>'8.00','valuation_source'=>'verified_act',
            'valuation_evidence'=>['version'=>1,'reference'=>'VALUE-POS-1','description'=>'Hipótesis de importe documentado NIO'] ],$extra);
    }

    private function input(): array
    {
        return ['operation_uuid'=>'a01b2b10-0000-4000-8000-000000000001','document_reference'=>'IN-MEMORY-ONLY',
            'effective_at'=>'2026-11-15T00:00:00-06:00','counted_at'=>'2026-11-15T00:00:00-06:00','count_declaration'=>true,
            'document_evidence'=>['version'=>1,'reference'=>'COUNT-ACT','description'=>'No autorización empresarial real'],
            'total_value_nio'=>'8.00','lines'=>[$this->line()]];
    }

    private function prepare(?array $input=null): array
    {
        return (new \App\Services\OpeningDocumentContract)->prepare($input ?? $this->input(),$this->items(),$this->policy(),7);
    }
}
