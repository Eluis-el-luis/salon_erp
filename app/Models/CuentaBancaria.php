<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaBancaria extends Model
{
    protected $table = 'cuentas_bancarias';
    protected $fillable = [
        'banco_id', 'numero_cuenta', 'tipo_cuenta', 'alias',
        'moneda_id', 'cuenta_contable_id', 'saldo_actual', 'activa'
    ];

    public function banco()
    {
        return $this->belongsTo(Banco::class, 'banco_id');
    }

    public function moneda()
    {
        return $this->belongsTo(Moneda::class, 'moneda_id');
    }

    /**
     * Subcuenta del mayor asociada a esta cuenta bancaria (estilo ERPNext:
     * una cuenta bancaria por moneda <=> una subcuenta contable).
     */
    public function cuentaContable()
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }

    // Relación: Cada cuenta acumula todos sus movimientos históricos
    public function movimientos()
    {
        return $this->hasMany(MovimientoBancario::class, 'cuenta_bancaria_id');
    }

    public function conciliaciones()
    {
        return $this->hasMany(ConciliacionBancaria::class, 'cuenta_bancaria_id');
    }

    public function esEnDolares(): bool
    {
        return $this->moneda && strtoupper($this->moneda->codigo) === 'USD';
    }

    public function etiqueta(): string
    {
        return trim(($this->banco->nombre ?? 'Banco') . ' ' . ($this->moneda->codigo ?? '') . ' • ' . $this->numero_cuenta);
    }
}
