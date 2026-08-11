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
class User extends Authenticatable
{
    use HasFactory, Notifiable;

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
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'cashier_id');
    }

    public function servicesPerformed()
    {
        return $this->hasMany(SaleDetail::class, 'stylist_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    // Las citas que el estilista tiene agendadas
    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'stylist_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    // Relación: Un empleado puede tener muchos adelantos
    public function advances()
    {
        return $this->hasMany(Advance::class);
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

