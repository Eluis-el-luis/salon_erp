<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * CUENTAS CONTABLES COMPLEMENTARIAS (fase corporativa Bancos / Mesa de Cambio).
 *
 * Inserta, de forma IDEMPOTENTE, las subcuentas que el motor contable resuelve
 * por código. Se invoca desde la migración 2026_10_11_000001 y desde los tests,
 * porque el catálogo base lo llena ContabilidadBaseSeeder (que corre DESPUÉS de
 * las migraciones en instalaciones nuevas).
 *
 *  - 1.1.1.1  Caja Córdobas (NIO)          (fase 1, se conserva por compatibilidad)
 *  - 1.1.1.2  Caja Dólares (USD)           (fase 1)
 *  - 1.1.1.3  Propinas en Custodia         (HRMS nómina)
 *  - 1.1.2.3  Banco BAC Dólares (USD)
 *  - 1.1.2.4  Banco Lafise Dólares (USD)
 *  - 1.1.7    IVA Crédito Fiscal (Compras)     (cadena de suministro / AP)
 *  - 2.1.5    Retenciones por Pagar (INSS/IR)  (nómina)
 *  - 2.1.6    Propinas por Pagar               (HRMS nómina)
 *  - 4.6      Ganancia por Diferencial de Cambio
 *  - 4.7      Ingresos por Intereses y Rendimientos
 *  - 6.8      Mermas y Desperdicios de Inventario  (inventario; la migración
 *             de mermas solo la crea si el catálogo ya existía al migrar)
 *  - 6.9      Comisiones y Cargos Bancarios
 *  - 7.5      Pérdida por Diferencial de Cambio
 */
class CuentasComplementariasSeeder extends Seeder
{
    public function run(): void
    {
        $this->asegurar('1.1.1.1', 'Caja Córdobas (NIO)', 'activo', 'deudora', '1.1.1');
        $this->asegurar('1.1.1.2', 'Caja Dólares (USD)', 'activo', 'deudora', '1.1.1');
        $this->asegurar('1.1.1.3', 'Propinas en Custodia', 'activo', 'deudora', '1.1.1');
        $this->asegurar('1.1.2.3', 'Banco BAC Dólares (USD)', 'activo', 'deudora', '1.1.2');
        $this->asegurar('1.1.2.4', 'Banco Lafise Dólares (USD)', 'activo', 'deudora', '1.1.2');
        $this->asegurar('1.1.7', 'IVA Crédito Fiscal (Compras)', 'activo', 'deudora', '1.1');
        $this->asegurar('2.1.5', 'Retenciones por Pagar (INSS/IR)', 'pasivo', 'acreedora', '2.1');
        $this->asegurar('2.1.6', 'Propinas por Pagar', 'pasivo', 'acreedora', '2.1');
        $this->asegurar('4.6', 'Ganancia por Diferencial de Cambio', 'ingreso', 'acreedora', '4');
        $this->asegurar('4.7', 'Ingresos por Intereses y Rendimientos', 'ingreso', 'acreedora', '4');
        $this->asegurar('6.8', 'Mermas y Desperdicios de Inventario', 'gasto', 'deudora', '6');
        $this->asegurar('6.9', 'Comisiones y Cargos Bancarios', 'gasto', 'deudora', '6');
        $this->asegurar('7.5', 'Pérdida por Diferencial de Cambio', 'gasto', 'deudora', '7');
    }

    protected function asegurar(string $codigo, string $nombre, string $tipo, string $naturaleza, string $codigoPadre): void
    {
        if (DB::table('cuentas_contables')->where('codigo', $codigo)->exists()) {
            return;
        }

        $padre = DB::table('cuentas_contables')->where('codigo', $codigoPadre)->first();
        if (! $padre) {
            return; // sin padre en el catálogo todavía: se insertará en la próxima corrida
        }

        DB::table('cuentas_contables')->insert([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'tipo' => $tipo,
            'naturaleza' => $naturaleza,
            'cuenta_padre_id' => $padre->id,
            'nivel' => $padre->nivel + 1,
            'permite_movimiento' => true,
            'activa' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
