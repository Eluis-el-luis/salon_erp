<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FASE 1 ADMINISTRACIÓN: el cliente es el centro de las Cuentas por Cobrar.
 * Además del directorio, concentra su historial (citas, facturas), su segmento
 * (grupo con descuento en POS) y las reglas de crédito: límite máximo y días
 * de crédito para determinar vencimientos.
 */
class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clients';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'customer_group_id',
        'credit_limit',
        'credit_days',
        'birthday',
        'notes',
    ];

    protected $casts = [
        'credit_limit' => 'float',
        'credit_days' => 'integer',
        'birthday' => 'date',
    ];

    // Un cliente puede tener muchas citas a lo largo del tiempo
    public function citas()
    {
        return $this->hasMany(Cita::class, 'client_id');
    }

    // Facturas emitidas a este cliente (sales)
    public function ventas()
    {
        return $this->hasMany(Venta::class, 'client_id');
    }

    // Grupo de fidelización (VIP, Regular, Mayorista...)
    public function grupo()
    {
        return $this->belongsTo(ClienteGrupo::class, 'customer_group_id');
    }

    /**
     * AR: saldo pendiente = suma de facturas EMITIDAS (por cobrar).
     * Las pagadas, anuladas y cotizaciones no comprometen crédito.
     */
    public function saldoPendiente(): float
    {
        return round(
            (float) $this->ventas()->where('status', Venta::ESTADO_EMITIDA)->sum('total'),
            2
        );
    }

    /**
     * Facturas a crédito VENCIDAS: emitidas cuyo vencimiento (fecha de emisión
     * + días de crédito del cliente) ya pasó. El POS bloquea nueva facturación
     * a crédito mientras existan.
     */
    public function facturasVencidas()
    {
        $dias = max(0, (int) ($this->credit_days ?? 0));

        return $this->ventas()
            ->where('status', Venta::ESTADO_EMITIDA)
            ->whereDate('created_at', '<', today()->subDays($dias));
    }

    public function tieneFacturasVencidas(): bool
    {
        return $this->facturasVencidas()->exists();
    }

    /**
     * REGLA DE DOMINIO (invariante de CxC): motivo de bloqueo para una nueva
     * venta a crédito de monto $montoNuevo. Null = permitida.
     * Se evalúa en VentaController dentro de la transacción con lockForUpdate,
     * y no depende de una sola pantalla.
     */
    public function motivoBloqueoCredito(float $montoNuevo): ?string
    {
        if ($this->tieneFacturasVencidas()) {
            return 'El cliente tiene facturas a crédito vencidas. Regularice su cuenta por cobrar antes de emitir una nueva factura a crédito.';
        }

        if ($this->credit_limit !== null) {
            $comprometido = round($this->saldoPendiente() + $montoNuevo, 2);

            if ($comprometido > (float) $this->credit_limit) {
                return 'Límite de crédito excedido: C$ ' . number_format($comprometido, 2)
                    . ' comprometidos de un límite de C$ ' . number_format((float) $this->credit_limit, 2)
                    . '. El cobro se registrará al contado o se debe ampliar el límite.';
            }
        }

        return null;
    }

    public function puedeTomarCredito(float $montoNuevo): bool
    {
        return $this->motivoBloqueoCredito($montoNuevo) === null;
    }

    /** Crédito disponible restante (null = sin límite definido) */
    public function creditoDisponible(): ?float
    {
        if ($this->credit_limit === null) {
            return null;
        }

        return round(max(0, (float) $this->credit_limit - $this->saldoPendiente()), 2);
    }
}
