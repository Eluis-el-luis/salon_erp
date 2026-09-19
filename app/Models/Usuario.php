<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class Usuario extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id', 
        'role',
        'salario_fijo',
        'comision_servicio',
        'comision_producto',
        'phone',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin_code', // Ocultamos el PIN por seguridad
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relaciones
    public function rol()
    {
        return $this->belongsTo(Rol::class);
    }

    public function ventas()
    {
        return $this->hasMany(Venta::class, 'cashier_id');
    }

    public function serviciosRealizados()
    {
        return $this->hasMany(DetalleVenta::class, 'stylist_id');
    }

    public function gastos()
    {
        return $this->hasMany(Gasto::class, 'user_id');
    }

    public function nominas()
    {
        return $this->hasMany(Nomina::class, 'user_id');
    }

    // Las citas que el estilista tiene agendadas
    public function citas()
    {
        return $this->hasMany(Cita::class, 'stylist_id');
    }

    public function asistencias()
    {
        return $this->hasMany(Asistencia::class, 'user_id');
    }

    // Relación: Un empleado puede tener muchos adelantos
    public function adelantos()
    {
        return $this->hasMany(Adelanto::class, 'user_id');
    }

    public function esquemasComision()
    {
        return $this->hasMany(EsquemaComision::class, 'empleado_id');
    }

    public function esquemaActual()
    {
        // Devuelve el esquema que no tiene fecha de caducidad o cuya fecha no ha pasado
        return $this->hasOne(EsquemaComision::class, 'empleado_id')
                    ->where(function ($query) {
                        $query->whereNull('vigente_hasta')
                              ->orWhere('vigente_hasta', '>=', now());
                    })
                    ->latest('vigente_desde');
    }

    public function comisionesGeneradas()
    {
        return $this->hasMany(ComisionGenerada::class, 'empleado_id');
    }
}

