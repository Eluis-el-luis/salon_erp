<?php

namespace Tests\Unit;

use App\Exceptions\ContabilidadException;
use App\Services\ConciliacionNomina as C;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Contrato económico puro, sin Laravel, modelos, PDO ni configuración empresarial. */
final class ConciliacionNominaTest extends TestCase
{
    private static function line(string $codigo, string $monto = '100.00'): array
    {
        [$tipo, $fuente] = match ($codigo) {
            'SAL_BASE' => ['ingreso', 'estructura'], 'HORAS_EXT' => ['ingreso', 'asistencia'],
            'COM_VENT' => ['ingreso', 'comisiones'], 'PROPINAS' => ['ingreso', 'propinas'],
            'INSS', 'IR' => ['deduccion', 'ley'], 'SIN_GOCE' => ['deduccion', 'ausencias'],
            'TARDANZAS' => ['deduccion', 'asistencia'], 'ADELANTOS' => ['deduccion', 'adelantos'],
        };
        return ['codigo' => $codigo, 'tipo' => $tipo, 'fuente' => $fuente, 'monto' => $monto,
            'referencia_id' => $codigo === 'ADELANTOS' ? 1 : null];
    }

    private static function totals(string $income, string $deduction, string $net): array
    {
        return ['total_ingresos' => $income, 'total_deducciones' => $deduction, 'neto_pagar' => $net];
    }

    public static function recognizedIncomes(): array { return [['SAL_BASE', 'sueldos'], ['HORAS_EXT', 'sueldos'], ['COM_VENT', 'comisiones'], ['PROPINAS', 'propinas']]; }

    #[DataProvider('recognizedIncomes')]
    public function test_each_existing_income_has_explicit_treatment(string $code, string $group): void
    {
        $r = C::resumir([self::line($code)], self::totals('100.00', '0.00', '100.00'));
        self::assertSame(10000, $r[$group]); self::assertSame(10000, $r['neto']);
    }

    public static function recognizedDeductions(): array { return [['INSS'], ['IR'], ['SIN_GOCE'], ['TARDANZAS'], ['ADELANTOS']]; }

    #[DataProvider('recognizedDeductions')]
    public function test_each_existing_deduction_reconciles_without_new_policy(string $code): void
    {
        $r = C::resumir([self::line('SAL_BASE', '200.00'), self::line($code)], self::totals('200.00', '100.00', '100.00'));
        self::assertSame(10000, $r['neto']); self::assertSame(10000, $r['fondo']);
    }

    public function test_combined_sources_and_custody_deficit_use_exact_cents(): void
    {
        $lines = [self::line('SAL_BASE', '100.01'), self::line('HORAS_EXT', '10.02'), self::line('COM_VENT', '20.03'),
            self::line('PROPINAS', '50.04'), self::line('INSS', '7.01'), self::line('IR', '3.02'),
            self::line('SIN_GOCE', '4.03'), self::line('TARDANZAS', '5.04'), self::line('ADELANTOS', '140.00')];
        $r = C::resumir($lines, self::totals('180.10', '159.10', '21.00'));
        self::assertSame(0, $r['fondo']); self::assertSame(2904, $r['absorcion']); self::assertSame(2100, $r['neto']);
        self::assertSame([5004, 0], C::asientoEsperado($r)['2.1.6']);
        self::assertSame([0, 5004], C::asientoEsperado($r)['1.1.1.3']);
    }

    public function test_multiple_advance_quotas_are_valid_but_same_quota_twice_is_not(): void
    {
        $first = self::line('ADELANTOS', '10.00'); $second = $first; $second['referencia_id'] = 2;
        $r = C::resumir([self::line('SAL_BASE'), $first, $second], self::totals('100', '20', '80'));
        self::assertSame(2000, $r['adelantos']);
        $this->expectException(ContabilidadException::class);
        C::resumir([self::line('SAL_BASE'), $first, $first], self::totals('100', '20', '80'));
    }

    public function test_duplicate_income_codes_reject_instead_of_paying_base_twice(): void
    {
        $this->expectException(ContabilidadException::class);
        C::resumir([self::line('SAL_BASE'), self::line('SAL_BASE')], self::totals('200', '0', '200'));
    }

    public function test_known_zero_is_valid_but_unknown_zero_has_no_authorized_treatment(): void
    {
        $r = C::resumir([self::line('SAL_BASE', '0.00')], self::totals('0', '0', '0'));
        self::assertSame([], C::asientoEsperado($r));
        $this->expectException(ContabilidadException::class);
        C::resumir([['codigo' => 'BONO', 'tipo' => 'ingreso', 'fuente' => 'manual', 'monto' => '0.00']], self::totals('0', '0', '0'));
    }

    public static function badAmounts(): array { return [['-0.01'], ['1.001'], ['NaN'], ['INF'], [INF], [NAN], ['1e2'], [true]]; }

    #[DataProvider('badAmounts')]
    public function test_invalid_line_amounts_reject_without_rounding($amount): void
    {
        $line = self::line('SAL_BASE'); $line['monto'] = $amount;
        $this->expectException(ContabilidadException::class);
        C::resumir([$line], self::totals('100', '0', '100'));
    }

    public function test_fixed_cannot_impersonate_known_income_or_deduction_by_code(): void
    {
        foreach (['SAL_BASE', 'INSS', 'BONO', 'DESCUENTO'] as $code) {
            try {
                C::validarEstructura([['activo' => true, 'calculadora' => 'fijo', 'codigo' => $code, 'valor' => '10.00']]);
                self::fail('Fijo no autorizado.');
            } catch (ContabilidadException $e) { self::assertStringContainsString('política autorizada', $e->getMessage()); }
        }
    }

    public function test_known_code_with_manual_source_is_rejected_by_accounting_contract(): void
    {
        $line = self::line('SAL_BASE'); $line['fuente'] = 'manual';
        $this->expectException(ContabilidadException::class);
        C::resumir([$line], self::totals('100', '0', '100'));
    }

    public function test_duplicate_calculators_and_unknown_calculator_reject_even_before_emitting_lines(): void
    {
        foreach ([
            [['activo' => true, 'calculadora' => 'salario_base'], ['activo' => true, 'calculadora' => 'salario_base']],
            [['activo' => true, 'calculadora' => 'manual']],
            [['activo' => true, 'calculadora' => 'salario_base', 'tipo' => 'deduccion']],
            [['activo' => true, 'calculadora' => 'horas_extras', 'valor' => -1]],
        ] as $structure) {
            try { C::validarEstructura($structure); self::fail('Estructura incompatible.'); }
            catch (ContabilidadException $e) { self::assertNotEmpty($e->getMessage()); }
        }
        C::validarEstructura([['activo' => false, 'calculadora' => 'fijo']]);
    }

    public function test_totals_differing_one_cent_or_negative_wage_expense_are_rejected(): void
    {
        foreach (['total_ingresos', 'total_deducciones', 'neto_pagar'] as $field) {
            $totals = self::totals('100', '0', '100'); $totals[$field] = '0.01';
            try { C::resumir([self::line('SAL_BASE')], $totals); self::fail('Totales incompatibles.'); }
            catch (ContabilidadException $e) { self::assertStringContainsString('liquidación', $e->getMessage()); }
        }
        $this->expectException(ContabilidadException::class);
        C::resumir([self::line('SAL_BASE', '10'), self::line('COM_VENT', '100'), self::line('TARDANZAS', '20')], self::totals('110', '20', '90'));
    }

    private function journal(): array
    {
        $r = C::resumir([self::line('SAL_BASE')], self::totals('100', '0', '100'));
        return [$r, [
            ['cuenta_id' => 67, 'moneda_id' => 1, 'debe' => '100.00', 'haber' => '0.00'],
            ['cuenta_id' => 111, 'moneda_id' => 1, 'debe' => '0.00', 'haber' => '100.00'],
        ]];
    }

    public function test_existing_explicit_accounts_match_and_balanced_omission_rejects(): void
    {
        [$r, $lines] = $this->journal();
        C::validarAsiento($r, $lines, ['6.7' => 67, 'fondo' => 111], 1);
        $lines[0]['debe'] = '90.00'; $lines[1]['haber'] = '90.00'; // Cuadrado, incompleto.
        $this->expectException(ContabilidadException::class);
        C::validarAsiento($r, $lines, ['6.7' => 67, 'fondo' => 111], 1);
    }

    public function test_fund_discrepancy_wrong_account_or_currency_rejects(): void
    {
        foreach (['fund', 'account', 'currency'] as $error) {
            [$r, $lines] = $this->journal();
            if ($error === 'fund') $lines[1]['haber'] = '99.99';
            if ($error === 'account') $lines[0]['cuenta_id'] = 53;
            if ($error === 'currency') $lines[0]['moneda_id'] = 2;
            try { C::validarAsiento($r, $lines, ['6.7' => 67, 'fondo' => 111], 1); self::fail('Asiento incompatible.'); }
            catch (ContabilidadException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }
}
