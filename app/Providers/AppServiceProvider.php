<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use App\Models\SesionCaja;
use App\Models\CuentaBancaria;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     * IMPORTANTE: morphMap debe registrarse ANTES de que se resuelva cualquier modelo
     * que use relaciones polimórficas (OperacionCambio, FondosService, etc.)
     * Incluye ambos nombres: el actual (SesionCaja) y el legado (CashSession)
     * que existe en datos históricos de la BD.
     */
    public function register(): void
    {
        Relation::morphMap([
            'App\Models\SesionCaja' => SesionCaja::class,
            'App\Models\CashSession' => SesionCaja::class,  // Legacy: datos históricos en BD
            'App\Models\CuentaBancaria' => CuentaBancaria::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
