<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * RBAC reestructurado (octubre 2026):
     *  - 'alvaro' es el rol SUPREMO (propietario): bypass global a cualquier
     *    ruta, incluida toda la contabilidad. Inquebrantable.
     *  - 'admin' ya NO tiene bypass: entra sólo donde se le enumera
     *    explícitamente (operación de caja, inventario, RRHH, bancos...).
     *    Las rutas contables (diario, mayor, resultados, catálogo, asientos,
     *    periodos) responden a 'alvaro' y 'contador' (auditoría financiera):
     *    un administrador que accede por URL directa recibe 403.
     *
     * Laravel pasa cada valor separado por coma como argumento individual
     * (role:admin,contador -> handle(..., 'admin', 'contador')), por lo que la
     * firma debe ser variádica.
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        // 1. Si no ha iniciado sesión, lo mandamos al login
        if (! auth()->check()) {
            return redirect('/login');
        }

        $user = auth()->user();

        // 2. SUPERPODER DEL PROPIETARIO: 'alvaro' entra a donde sea.
        if ($user->role === 'alvaro') {
            return $next($request);
        }

        // 3. Se permiten varios roles (por coma dentro de cada argumento o como
        //    argumentos separados): role:recepcion,contador
        $rolesPermitidos = collect($roles)
            ->flatMap(fn ($rol) => explode(',', $rol))
            ->map(fn ($rol) => trim($rol))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (in_array($user->role, $rolesPermitidos, true)) {
            return $next($request);
        }

        // 4. Si no coincide ningún rol permitido, BLOQUEADO
        abort(403, 'ACCESO RESTRINGIDO: Tu perfil de '.strtoupper($user->role).' no tiene autorización para entrar aquí.');
    }
}
