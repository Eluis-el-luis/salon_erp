<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Articulo extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'items';

    protected $fillable = [
        'codigo',
        'ubicacion',
        'producto',
        'categoria',
        'marca',
        'type',
        'precio_c',
        'precio_usd',
        'existencia_actual',
        'stock_min',
        'provider_id',
        'is_fractionable',
        'unit_measure',
        'total_volume',
        'current_volume',
    ];

    // Las veces que este articulo ha sido agendado
    public function citas()
    {
        return $this->hasMany(Cita::class, 'service_id');
    }

    public function detallesVenta()
    {
        return $this->hasMany(DetalleVenta::class, 'item_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function lotes()
    {
        return $this->hasMany(Lote::class, 'item_id');
    }

    // Recetas estándar que consumen este insumo (BOM)
    public function formulas()
    {
        return $this->hasMany(FormulaServicio::class, 'item_id');
    }

    public function consumosVenta()
    {
        return $this->hasMany(ConsumoVenta::class, 'item_id');
    }

    /**
     * FASE 1 ADMINISTRACIÓN: costo de compra ACTUAL por unidad física.
     * Promedio ponderado de los lotes abiertos; si no hay lotes con stock,
     * fallback al último lote registrado (histórico); null si nunca hubo costo.
     */
    public function costoUnitarioActual(): ?float
    {
        $mapa = self::mapaCostosActuales([$this->id]);

        return $mapa[$this->id]['costo_unitario'];
    }

    /**
     * Costo por unidad de MEDIDA (ml/oz) para insumos fraccionables: es la base
     * para valorar los mililitros de una receta. Para artículos por unidad es
     * el costo unitario directo.
     */
    public function costoPorUnidadMedida(): ?float
    {
        $costoUnitario = $this->costoUnitarioActual();

        if ($costoUnitario === null) {
            return null;
        }

        if ($this->is_fractionable && (float) $this->total_volume > 0) {
            return round($costoUnitario / (float) $this->total_volume, 6);
        }

        return round($costoUnitario, 6);
    }

    /**
     * Mapa de costos de compra actuales para un lote de artículos (evita N+1):
     * item_id => ['costo_unitario' => ?float, 'fuente' => lotes|ultimo_lote|sin_costo].
     */
    public static function mapaCostosActuales(array $itemIds): array
    {
        $mapa = [];
        foreach (array_unique($itemIds) as $id) {
            $mapa[$id] = ['costo_unitario' => null, 'fuente' => 'sin_costo'];
        }

        if (count($mapa) === 0) {
            return $mapa;
        }

        // 1) Promedio ponderado de los lotes abiertos (con stock disponible)
        $abiertos = Lote::whereIn('item_id', array_keys($mapa))
            ->where('cantidad_disponible', '>', 0)
            ->selectRaw('item_id, SUM(cantidad_disponible * costo_unitario) AS valor_total, SUM(cantidad_disponible) AS cantidad_total')
            ->groupBy('item_id')
            ->get();

        foreach ($abiertos as $fila) {
            if ((float) $fila->cantidad_total > 0) {
                $mapa[$fila->item_id] = [
                    'costo_unitario' => round((float) $fila->valor_total / (float) $fila->cantidad_total, 6),
                    'fuente' => 'lotes',
                ];
            }
        }

        // 2) Fallback: último lote registrado aunque esté agotado
        $faltantes = array_keys(array_filter($mapa, fn ($info) => $info['fuente'] === 'sin_costo'));

        if (count($faltantes) > 0) {
            $ultimos = Lote::whereIn('item_id', $faltantes)
                ->orderBy('fecha_entrada', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->groupBy('item_id');

            foreach ($ultimos as $itemId => $lotes) {
                $ultimo = $lotes->last();

                if ($ultimo && (float) $ultimo->costo_unitario > 0) {
                    $mapa[$itemId] = [
                        'costo_unitario' => round((float) $ultimo->costo_unitario, 6),
                        'fuente' => 'ultimo_lote',
                    ];
                }
            }
        }

        return $mapa;
    }
}
