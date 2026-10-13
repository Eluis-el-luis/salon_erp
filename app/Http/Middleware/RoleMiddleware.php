<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Laravel pasa cada valor separado por coma como argumento individual
     * (role:admin,contador -> handle(..., 'admin', 'contador')), por lo que la
     * firma debe ser variádica; antes sólo se leía el PRIMER rol y el contador
     * era bloqueado en todas las rutas compartidas con recepción.
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        // 1. Si no ha iniciado sesión, lo mandamos al login
        if (! auth()->check()) {
            return redirect('/login');
        }

        $user = auth()->user();

        // 2. SUPERPODER DEL ADMIN: Si el usuario es 'admin', entra a donde sea.
        if ($user->role === 'admin') {
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
