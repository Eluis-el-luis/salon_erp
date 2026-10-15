<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PROVEEDOR (Supplier): directorio comercial + posición de Cuentas por Pagar.
 * El saldo AP es SIEMPRE derivado de las cuentas_por_pagar abiertas (nunca un
 * campo guardado), de modo que los abonos del módulo de CxP lo reflejan al
 * instante, incluidas las CxP heredadas del flujo antiguo de compras.
 */
class Proveedor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'providers';

    // Permitir la asignación masiva para estos campos
    protected $fillable = [
        'name',
        'tax_id',
        'contact_name',
        'phone',
        'email',
        'address',
        'notes',
        'credit_days',
        'is_active',
    ];

    protected $casts = [
        'credit_days' => 'integer',
        'is_active' => 'boolean',
    ];

    // Relación opcional: Un proveedor tiene muchos productos
    public function articulos()
    {
        return $this->hasMany(Articulo::class, 'provider_id');
    }

    // Relación: Un proveedor tiene muchas cuentas por pagar (facturas pendientes)
    public function cuentasPorPagar()
    {
        return $this->hasMany(CuentaPorPagar::class, 'provider_id');
    }

    public function facturasCompra()
    {
        return $this->hasMany(FacturaCompra::class, 'provider_id');
    }

    /**
     * Mermas de los artículos que suministra (historial de devoluciones/bajas).
     * Incluye artículos dados de baja del catálogo (SoftDeletes): la auditoría
     * no desaparece con el artículo.
     */
    public function mermasDeArticulos()
    {
        return Merma::query()->whereIn('item_id', $this->articulos()->withTrashed()->select('id'));
    }

    /**
     * AR DEL PROVEEDOR: saldo pendiente = suma de CxP abiertas
     * (pendiente/parcial). Incluye las CxP del flujo antiguo de compras.
     */
    public function saldoPendiente(): float
    {
        return round((float) $this->cuentasPorPagar()
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->sum('saldo_pendiente'), 2);
    }

    /**
     * CxP abiertas cuyo vencimiento ya pasó (emisión + días de crédito).
     */
    public function facturasVencidas()
    {
        return $this->cuentasPorPagar()
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->where('fecha_vencimiento', '<', now()->toDateString());
    }

    public function montoVencido(): float
    {
        return round((float) $this->facturasVencidas()->sum('saldo_pendiente'), 2);
    }

    /**
     * Total facturado por el proveedor en compras vigentes (no anuladas).
     */
    public function totalComprado(): float
    {
        return round((float) $this->facturasCompra()
            ->where('estado', FacturaCompra::ESTADO_REGISTRADA)
            ->sum('total'), 2);
    }

    /**
     * Total pagado al proveedor vía abonos de CxP (incluye el histórico).
     */
    public function totalPagado(): float
    {
        return round((float) PagoProveedor::query()
            ->whereIn('cuenta_por_pagar_id', $this->cuentasPorPagar()->select('id'))
            ->sum('monto'), 2);
    }
}
