<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, \Closure $next, string $roles)
    {
        // 1. Si no ha iniciado sesión, lo mandamos al login
        if (!auth()->check()) {
            return redirect('/login');
        }

        $user = auth()->user();

        // 2. SUPERPODER DEL ADMIN: Si el usuario es 'admin', entra a donde sea.
        if ($user->role === 'admin') {
            return $next($request);
        }

        // 3. Se permiten varios roles separados por coma: role:recepcion,contador
        $rolesPermitidos = array_map('trim', explode(',', $roles));

        if (in_array($user->role, $rolesPermitidos)) {
            return $next($request);
        }

        // 4. Si no coincide ningún rol permitido, BLOQUEADO
        abort(403, 'ACCESO RESTRINGIDO: Tu perfil de ' . strtoupper($user->role) . ' no tiene autorización para entrar aquí.');
    }
}
