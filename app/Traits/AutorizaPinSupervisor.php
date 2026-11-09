<?php

namespace App\Traits;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * DOBLE CONFIRMACIÓN POR PIN (Supervisor Override).
 *
 * Trait reutilizable para validar el PIN de autorización en acciones
 * críticas de caja y finanzas: abrir/cerrar arqueo, salidas de caja chica,
 * anulaciones y aprobaciones. El PIN (4-6 dígitos) se define en el perfil
 * del usuario y se guarda hasheado en users.pin_seguridad.
 *
 * Reglas de autorización:
 *  - Modo estándar: vale el PIN del propio usuario autenticado O el de
 *    cualquier rol supervisor (config('salon.seguridad.roles_supervisor')).
 *  - Modo estricto (soloSupervisor = true): SOLO vale el PIN de un rol
 *    supervisor; el propio PIN de un cajero NO desbloquea la acción
 *    (ej. anulación de facturas).
 *
 * Cada intento fallido alimenta un limitador (5 por minuto por usuario)
 * y cada uso exitoso queda registrado en el log de aplicación para auditoría.
 */
trait AutorizaPinSupervisor
{
    /**
     * Valida el PIN enviado en 'pin_supervisor' y devuelve el usuario que
     * autoriza la acción. Lanza ValidationException si no es válido.
     */
    protected function autorizarPin(Request $request, bool $soloSupervisor = false): Usuario
    {
        $pin = trim((string) $request->input('pin_supervisor'));

        if ($pin === '') {
            throw ValidationException::withMessages([
                'pin_supervisor' => $soloSupervisor
                    ? 'Esta acción requiere el PIN de autorización de un supervisor ('.implode(' / ', config('salon.seguridad.roles_supervisor', ['alvaro', 'admin'])).').'
                    : 'Confirma esta acción con tu PIN de seguridad o el PIN de un supervisor.',
            ]);
        }

        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw ValidationException::withMessages([
                'pin_supervisor' => 'El PIN de autorización debe tener entre 4 y 6 dígitos.',
            ]);
        }

        $claveLimite = 'pin-supervisor:'.auth()->id();

        if (RateLimiter::tooManyAttempts($claveLimite, 5)) {
            throw ValidationException::withMessages([
                'pin_supervisor' => 'Demasiados intentos fallidos. Espera '.RateLimiter::availableIn($claveLimite).' segundos antes de reintentar.',
            ]);
        }

        $rolesSupervisor = config('salon.seguridad.roles_supervisor', ['alvaro', 'admin']);

        $candidatos = Usuario::query()
            ->where('is_active', true)
            ->whereNotNull('pin_seguridad')
            ->whereIn('role', $rolesSupervisor)
            ->when(!$soloSupervisor && auth()->check(), function ($q) {
                // Modo estándar: también se acepta el PIN propio del usuario
                $q->orWhere('id', auth()->id());
            })
            ->get();

        foreach ($candidatos as $candidato) {
            if (Hash::check($pin, $candidato->pin_seguridad)) {
                $esSupervisor = in_array($candidato->role, $rolesSupervisor, true);
                $esPropio = !$soloSupervisor && $candidato->id === (int) auth()->id();

                if ($esSupervisor || $esPropio) {
                    RateLimiter::clear($claveLimite);

                    Log::info('Acción crítica autorizada con PIN de supervisor', [
                        'usuario_accion' => auth()->id(),
                        'rol_usuario_accion' => auth()->user()->role ?? null,
                        'autorizado_por' => $candidato->id,
                        'rol_autorizador' => $candidato->role,
                        'modo' => $soloSupervisor ? 'solo_supervisor' : 'estandar',
                        'ruta' => $request->path(),
                    ]);

                    return $candidato;
                }
            }
        }

        RateLimiter::hit($claveLimite, 60);

        throw ValidationException::withMessages([
            'pin_supervisor' => 'PIN de autorización incorrecto. La acción no fue ejecutada.',
        ]);
    }
}
