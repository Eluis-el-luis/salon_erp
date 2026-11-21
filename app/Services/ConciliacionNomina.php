<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Support\InventoryDecimal as D;

/** Contrato del asiento HRMS vigente. No autoriza clasificaciones salariales nuevas. */
final class ConciliacionNomina
{
    private const CONCEPTOS = [
        'SAL_BASE' => ['salario_base', 'ingreso', 'estructura'],
        'HORAS_EXT' => ['horas_extras', 'ingreso', 'asistencia'],
        'COM_VENT' => ['comisiones', 'ingreso', 'comisiones'],
        'PROPINAS' => ['propinas', 'ingreso', 'propinas'],
        'INSS' => ['inss', 'deduccion', 'ley'],
        'IR' => ['ir', 'deduccion', 'ley'],
        'SIN_GOCE' => ['sin_goce', 'deduccion', 'ausencias'],
        'TARDANZAS' => ['tardanzas', 'deduccion', 'asistencia'],
        'ADELANTOS' => ['adelantos', 'deduccion', 'adelantos'],
    ];

    public static function validarEstructura(iterable $detalles): void
    {
        $calculadoras = []; $codigos = [];
        foreach ($detalles as $detalle) {
            if (! $detalle['activo']) continue;
            $calculadora = $detalle['calculadora'];
            $codigo = $detalle['codigo'] ?? null;
            $contrato = array_values(array_filter(self::CONCEPTOS, fn ($c) => $c[0] === $calculadora))[0] ?? null;
            if (! $contrato) {
                throw new ContabilidadException('Concepto '.($codigo ?? $calculadora).': tratamiento contable no definido. Requiere política autorizada; no se admite como fijo ni por similitud de código.');
            }
            if (isset($calculadoras[$calculadora]) || ($codigo !== null && isset($codigos[$codigo]))) {
                throw new ContabilidadException('Conceptos o calculadoras duplicados en la estructura salarial.');
            }
            if (isset($detalle['tipo']) && $detalle['tipo'] !== $contrato[1]) {
                throw new ContabilidadException('Tipo incompatible con la calculadora salarial '.$calculadora.'.');
            }
            if (in_array($calculadora, ['horas_extras', 'tardanzas'], true)) {
                $valor = $detalle['valor'] ?? 0;
                if (! is_numeric($valor) || ! is_finite((float) $valor) || $valor < 0) {
                    throw new ContabilidadException('Parámetro salarial negativo o inválido.');
                }
            }
            $calculadoras[$calculadora] = true;
            if ($codigo !== null) $codigos[$codigo] = true;
        }
    }

    /** Importes devueltos en centavos; acepta arrays de cálculo y detalles Eloquent persistidos. */
    public static function resumir(iterable $lineas, array $totales): array
    {
        $sumas = array_fill_keys(array_keys(self::CONCEPTOS), 0);
        $vistos = []; $ingresos = 0; $deducciones = 0;
        foreach ($lineas as $linea) {
            $codigo = $linea['codigo'] ?? '';
            $contrato = self::CONCEPTOS[$codigo] ?? null;
            if (! $contrato || ($linea['tipo'] ?? null) !== $contrato[1] || ($linea['fuente'] ?? null) !== $contrato[2]) {
                throw new ContabilidadException('Concepto '.$codigo.': código, tipo o fuente sin tratamiento contable explícito.');
            }
            $clave = $codigo;
            if ($codigo === 'ADELANTOS') {
                $referencia = $linea['referencia_id'] ?? null;
                if (filter_var($referencia, FILTER_VALIDATE_INT) === false || (int) $referencia <= 0) {
                    throw new ContabilidadException('La amortización requiere referencia de cuota válida.');
                }
                $clave .= ':'.$referencia;
            }
            if (isset($vistos[$clave])) throw new ContabilidadException('Concepto duplicado en la colilla: '.$clave.'.');
            $vistos[$clave] = true;
            $monto = D::scaled($linea['monto'] ?? null, 2, 9999999999); // salary_slip_details decimal(10,2)
            $sumas[$codigo] += $monto;
            if ($contrato[1] === 'ingreso') $ingresos += $monto;
            else $deducciones += $monto;
        }
        $neto = $ingresos - $deducciones;
        if ($neto < 0
            || D::scaled($totales['total_ingresos'] ?? null, 2, 999999999999) !== $ingresos
            || D::scaled($totales['total_deducciones'] ?? null, 2, 999999999999) !== $deducciones
            || D::scaled($totales['neto_pagar'] ?? null, 2, 999999999999) !== $neto) {
            throw new ContabilidadException('La liquidación no concilia conceptos, devengado, deducciones y neto.');
        }
        $sueldos = $sumas['SAL_BASE'] + $sumas['HORAS_EXT'] - $sumas['SIN_GOCE'] - $sumas['TARDANZAS'];
        if ($sueldos < 0) {
            throw new ContabilidadException('Las reducciones exceden el gasto salarial reconocido. Requiere revisión; no se truncan importes.');
        }
        $comisiones = $sumas['COM_VENT']; $propinas = $sumas['PROPINAS'];
        $retenciones = $sumas['INSS'] + $sumas['IR']; $adelantos = $sumas['ADELANTOS'];
        $absorcion = max(0, $adelantos + $retenciones - $sueldos - $comisiones);
        $fondo = max(0, $sueldos + $comisiones - $adelantos - $retenciones);
        if ($absorcion > $propinas || $fondo + $propinas - $absorcion !== $neto) {
            throw new ContabilidadException('El neto no concilia fondo y liberación de custodia.');
        }
        return ['sueldos' => $sueldos, 'comisiones' => $comisiones, 'propinas' => $propinas,
            'retenciones' => $retenciones, 'adelantos' => $adelantos, 'absorcion' => $absorcion,
            'fondo' => $fondo, 'neto' => $neto];
    }

    /** Vector por cuenta, sin compensar debe/haber entre sí. Corresponde al asiento existente. */
    public static function asientoEsperado(array $r): array
    {
        return array_filter([
            '6.7' => [$r['sueldos'], 0], '5.3' => [$r['comisiones'], 0], '2.1.6' => [$r['propinas'], 0],
            '1.1.6' => [0, $r['adelantos']], '2.1.5' => [0, $r['retenciones']],
            '1.1.1.3' => [0, $r['propinas']], '1.1.1' => [$r['absorcion'], 0], 'fondo' => [0, $r['fondo']],
        ], fn ($par) => $par !== [0, 0]);
    }

    public static function validarAsiento(array $resumen, iterable $lineas, array $cuentas, int $moneda): void
    {
        $esperado = []; $real = [];
        foreach (self::asientoEsperado($resumen) as $codigo => $par) {
            $id = $cuentas[$codigo] ?? null;
            if (! $id) throw new ContabilidadException('Cuenta de nómina no resuelta: '.$codigo.'.');
            self::acumular($esperado, (int) $id, $par[0], $par[1]);
        }
        foreach ($lineas as $linea) {
            if ((int) $linea['moneda_id'] !== $moneda) throw new ContabilidadException('Moneda incompatible en asiento de nómina.');
            self::acumular($real, (int) $linea['cuenta_id'], D::scaled($linea['debe']), D::scaled($linea['haber']));
        }
        ksort($esperado); ksort($real);
        if ($esperado !== $real) {
            throw new ContabilidadException('El asiento no concilia conceptos, cuentas, custodia y fondo de la colilla.');
        }
    }

    private static function acumular(array &$vector, int $id, int $debe, int $haber): void
    {
        if ($debe === 0 && $haber === 0) return;
        $vector[$id] ??= [0, 0];
        $vector[$id][0] += $debe; $vector[$id][1] += $haber;
    }
}
