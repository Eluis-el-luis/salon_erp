<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoBancario extends Model
{
    protected $table = 'movimientos_bancarios';
    protected $fillable = [
        'cuenta_bancaria_id', 'moneda_id', 'tipo', 'monto', 'fecha',
        'concepto', 'modulo_origen', 'referencia_id', 'conciliado',
        'estado_conciliacion', 'fecha_conciliacion', 'referencia_banco',
        'asiento_contable_id', 'conciliacion_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_conciliacion' => 'date',
    ];

    public function cuentaBancaria()
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function moneda()
    {
        return $this->belongsTo(Moneda::class, 'moneda_id');
    }

    public function asientoContable()
    {
        return $this->belongsTo(AsientoContable::class, 'asiento_contable_id');
    }

    public function conciliacion()
    {
        return $this->belongsTo(ConciliacionBancaria::class, 'conciliacion_id');
    }

    /**
     * Signo del movimiento en el extracto: +1 entra dinero, -1 sale dinero.
     * El enum histórico es ['deposito','retiro','cargo','abono'].
     */
    public function signo(): int
    {
        return in_array($this->tipo, ['deposito', 'abono']) ? 1 : -1;
    }
}
