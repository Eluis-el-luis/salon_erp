<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee (RRHH): ciclo de vida del colaborador, separado del User (acceso).
 * Un User conoce el sistema; un Employee tiene contrato, salario, turno y expediente.
 */
class Empleado extends Model
{
    protected $table = 'employees';

    protected $fillable = [
        'user_id',
        'codigo_empleado',
        'salary_structure_id',
        'tipo_contrato',
        'fecha_ingreso',
        'fecha_salida',
        'estado_laboral',
        'cargo',
        'salario_base',
        'periodicidad_pago',
        'documento_identidad',
        'nss',
        'banco_deposito',
        'cuenta_bancaria_deposito',
        'direccion',
        'notas',
    ];

    protected $casts = [
        'fecha_ingreso' => 'date',
        'fecha_salida' => 'date',
        'salario_base' => 'decimal:2',
    ];

    // ------------------------------------------------------------------
    // Relaciones
    // ------------------------------------------------------------------

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function estructura()
    {
        return $this->belongsTo(EstructuraSalarial::class, 'salary_structure_id');
    }

    public function asignacionesTurno()
    {
        return $this->hasMany(AsignacionTurno::class, 'employee_id')->orderByDesc('vigente_desde');
    }

    public function ausencias()
    {
        return $this->hasMany(Ausencia::class, 'employee_id')->orderByDesc('fecha_inicio');
    }

    public function propinas()
    {
        return $this->hasMany(Propina::class, 'employee_id')->orderByDesc('fecha');
    }

    public function colillas()
    {
        return $this->hasMany(ColillaNomina::class, 'employee_id')->orderByDesc('periodo_fin');
    }

    // ------------------------------------------------------------------
    // Helpers de dominio
    // ------------------------------------------------------------------

    public function getNombreAttribute(): string
    {
        return $this->usuario->name ?? $this->codigo_empleado;
    }

    /** Turno vigente hoy (asignación abierta o cuya fecha de fin cubre hoy). */
    public function turnoVigente(): ?Turno
    {
        $hoy = Carbon::today()->toDateString();

        $asignacion = $this->asignacionesTurno()
            ->whereDate('vigente_desde', '<=', $hoy)
            ->where(function ($q) use ($hoy) {
                $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $hoy);
            })
            ->first();

        return $asignacion?->turno;
    }

    /** Estructura salarial vigente: la asignada o la activa por defecto. */
    public function estructuraActual(): ?EstructuraSalarial
    {
        if ($this->estructura && $this->estructura->activa) {
            return $this->estructura;
        }

        return EstructuraSalarial::activaPorDefecto();
    }

    /** Crea el registro Employee vinculado a un usuario (código auto EMP-XXXX). */
    public static function crearParaUsuario(Usuario $usuario, array $datos = []): self
    {
        return static::updateOrCreate(
            ['user_id' => $usuario->id],
            array_merge([
                'codigo_empleado' => static::siguienteCodigo($usuario->id),
                'tipo_contrato' => 'indefinido',
                'fecha_ingreso' => Carbon::today()->toDateString(),
                'estado_laboral' => 'activo',
                'salario_base' => $usuario->salario_fijo ?? 0,
            ], $datos),
        );
    }

    public static function siguienteCodigo(int $seed): string
    {
        $max = static::max('id') ?? 0;

        return 'EMP-'.str_pad((string) max($seed, $max + 1), 4, '0', STR_PAD_LEFT);
    }
}
