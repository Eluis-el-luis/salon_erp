<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conciliación Bancaria (estilo ERPNext Bank Reconciliation):
 * fotografia un arqueo del libro bancario contra el extracto real del banco.
 * Una conciliación sólo se guarda cerrada cuando la diferencia es C$ 0.00;
 * las diferencias se corrigen registrando el cargo/abono bancario faltante.
 */
class ConciliacionBancaria extends Model
{
    protected $table = 'conciliaciones_bancarias';

    protected $fillable = [
        'cuenta_bancaria_id', 'fecha', 'saldo_inicial_conciliado',
        'saldo_extracto', 'saldo_libros', 'diferencia', 'estado',
        'observaciones', 'usuario_id',
    ];

    protected $casts = ['fecha' => 'date'];

    public function cuentaBancaria()
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoBancario::class, 'conciliacion_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
