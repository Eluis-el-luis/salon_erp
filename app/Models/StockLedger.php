<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * STOCK LEDGER (KÁRDEX INMUTABLE): libro mayor del inventario. El inventario
 * NO se edita: todo cambio es un registro de entrada (compra/ajuste/apertura),
 * salida (venta/merma/anulación de compra) o reversión, con su valor al costo
 * promedio móvil y el SALDO CORRIDO después del movimiento.
 *
 * MEDIDA PRIMARIA DE VALORACIÓN:
 *  - Artículos fraccionables: el VOLUMEN (ml/oz). `saldo_volumen` es la posición
 *    valorable; `saldo_cantidad` es la posición en frascos/unidades (referencial).
 *  - Resto: la UNIDAD. `saldo_cantidad` es la posición valorable.
 *
 * CONCURRENCIA: registrar() bloquea la última fila del artículo
 * (lockForUpdate) para serializar el saldo corrido; debe invocarse DENTRO de
 * una transacción (la dueña del caso de uso).
 */
class StockLedger extends Model
{
    use HasFactory;

    protected $table = 'stock_ledger_entries';

    public const ORIGEN_COMPRA = 'compra';

    public const ORIGEN_VENTA = 'venta';

    public const ORIGEN_MERMA = 'merma';

    public const ORIGEN_AJUSTE = 'ajuste';

    public const ORIGEN_APERTURA = 'apertura';

    public const ORIGEN_REVERSION_VENTA = 'reversion_venta';

    public const ORIGEN_REVERSION_MERMA = 'reversion_merma';

    public const ORIGEN_ANULACION_COMPRA = 'anulacion_compra';

    protected $fillable = [
        'item_id',
        'fecha',
        'movimiento',
        'origen',
        'referencia_tipo',
        'referencia_id',
        'cantidad',
        'volumen',
        'costo_unitario',
        'valor',
        'saldo_cantidad',
        'saldo_volumen',
        'saldo_valor',
        'costo_promedio',
        'usuario_id',
        'notas',
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'cantidad' => 'float',
        'volumen' => 'float',
        'costo_unitario' => 'float',
        'valor' => 'float',
        'saldo_cantidad' => 'float',
        'saldo_volumen' => 'float',
        'saldo_valor' => 'float',
        'costo_promedio' => 'float',
    ];

    public function articulo()
    {
        return $this->belongsTo(Articulo::class, 'item_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /**
     * Registra un movimiento con saldo corrido y devuelve la entrada creada.
     *
     * @param  Articulo  $articulo  Artículo movido (posición física ya actualizada por el llamador).
     * @param  string  $movimiento  entrada|salida|ajuste
     * @param  string  $origen  Constante ORIGEN_*.
     * @param  float  $unidades  Delta en UNIDADES/FRASCOS con signo.
     * @param  float|null  $volumenMl  Delta en ml/oz con signo (fraccionables). Si es null y hay
     *                                 unidades, se asumen botellas completas (unidades × volumen).
     * @param  float|null  $costoUnitario  Costo por UNIDAD/FRASCO de la operación. null en salidas =>
     *                                     se valora al costo promedio del propio ledger (moving average).
     * @param  string|null  $referenciaTipo  Ej.: factura_compra, venta, merma.
     * @param  mixed  $referenciaId  Id del documento de origen.
     * @param  int|null  $usuarioId  Usuario responsable.
     * @param  string|null  $notas  Contexto auditable.
     */
    public static function registrar(
        Articulo $articulo,
        string $movimiento,
        string $origen,
        float $unidades,
        ?float $volumenMl = null,
        ?float $costoUnitario = null,
        ?string $referenciaTipo = null,
        $referenciaId = null,
        ?int $usuarioId = null,
        ?string $notas = null,
    ): self {
        $esFraccionable = $articulo->is_fractionable && (float) $articulo->total_volume > 0;
        $totalVolumen = $esFraccionable ? (float) $articulo->total_volume : 0.0;

        // Delta de la medida primaria de valoración (ml para fraccionables, unidades para el resto)
        if ($esFraccionable) {
            $deltaPrimario = (float) ($volumenMl ?? 0.0);
            if ($deltaPrimario == 0.0 && abs($unidades) > 0) {
                $deltaPrimario = $unidades * $totalVolumen; // botellas completas
            }
        } else {
            $deltaPrimario = $unidades;
        }

        // Saldo previo del artículo (bloqueado para serializar concurrencia)
        $ultimo = static::where('item_id', $articulo->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $saldoPrimarioPrevio = 0.0;
        $saldoValorPrevio = 0.0;
        $saldoUnidadesPrevio = 0.0;

        if ($ultimo) {
            $saldoPrimarioPrevio = $esFraccionable ? (float) $ultimo->saldo_volumen : (float) $ultimo->saldo_cantidad;
            $saldoValorPrevio = (float) $ultimo->saldo_valor;
            $saldoUnidadesPrevio = (float) $ultimo->saldo_cantidad;
        }

        // Costo de valoración: el dado por la operación (entradas) o el promedio
        // móvil vigente del ledger (salidas); fallback al costo actual por lotes.
        $costoPrimario = null;

        if ($costoUnitario !== null) {
            $costoPrimario = $esFraccionable ? ($totalVolumen > 0 ? $costoUnitario / $totalVolumen : 0.0) : $costoUnitario;
        } elseif (abs($saldoPrimarioPrevio) > 0.0000001) {
            $costoPrimario = $saldoValorPrevio / $saldoPrimarioPrevio;
        } else {
            // Sin historial valorado: fallback al costo actual por lotes y, en
            // último caso, al costo promedio heredado del artículo (apertura).
            $costoPrimario = $esFraccionable
                ? ($articulo->costoPorUnidadMedida()
                    ?? (($articulo->total_volume > 0 && (float) $articulo->costo_promedio > 0)
                        ? (float) $articulo->costo_promedio / (float) $articulo->total_volume
                        : 0.0))
                : ($articulo->costoUnitarioActual()
                    ?? ((float) $articulo->costo_promedio > 0 ? (float) $articulo->costo_promedio : 0.0));
        }

        $costoPrimario = round((float) $costoPrimario, 6);
        $valor = round($deltaPrimario * $costoPrimario, 2);

        $saldoValor = round($saldoValorPrevio + $valor, 2);
        $saldoPrimario = round($saldoPrimarioPrevio + $deltaPrimario, 4);
        $saldoUnidades = round($saldoUnidadesPrevio + $unidades, 4);

        // Promedio móvil vigente después del movimiento (en la salida se conserva)
        $costoPromedio = abs($saldoPrimario) > 0.0000001
            ? round($saldoValor / $saldoPrimario, 6)
            : 0.0;

        return static::create([
            'item_id' => $articulo->id,
            'fecha' => now(),
            'movimiento' => $movimiento,
            'origen' => $origen,
            'referencia_tipo' => $referenciaTipo,
            'referencia_id' => $referenciaId,
            'cantidad' => round($unidades, 4),
            'volumen' => $esFraccionable ? round($deltaPrimario, 4) : null,
            'costo_unitario' => $costoPrimario,
            'valor' => $valor,
            'saldo_cantidad' => $saldoUnidades,
            'saldo_volumen' => $esFraccionable ? $saldoPrimario : null,
            'saldo_valor' => $saldoValor,
            'costo_promedio' => $costoPromedio,
            'usuario_id' => $usuarioId ?? auth()->id(),
            'notas' => $notas,
        ]);
    }

    /**
     * APERTURA DEL KÁRDEX: fija el saldo inicial de un artículo que ya tenía
     * stock antes de existir el ledger. Es un registro real y auditable
     * (no una reconstrucción histórica); solo aplica si no hay movimientos.
     */
    public static function registrarApertura(Articulo $articulo, ?int $usuarioId = null, ?string $notas = null): ?self
    {
        if (static::where('item_id', $articulo->id)->exists()) {
            return null;
        }

        $esFraccionable = $articulo->is_fractionable && (float) $articulo->total_volume > 0;
        $unidades = (float) $articulo->existencia_actual;
        $volumenTotal = $esFraccionable
            ? ($unidades * (float) $articulo->total_volume) + (float) $articulo->current_volume
            : null;

        $movimiento = ($unidades > 0 || ($volumenTotal !== null && $volumenTotal > 0)) ? 'entrada' : 'ajuste';

        return static::registrar(
            $articulo,
            $movimiento,
            self::ORIGEN_APERTURA,
            $unidades,
            $volumenTotal,
            null,
            self::ORIGEN_APERTURA,
            $articulo->id,
            $usuarioId,
            $notas ?? 'Saldo de apertura del inventario previo al Kárdex corporativo',
        );
    }
}
