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
    public function handle(Request $request, \Closure $next, string $role)
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

        // 3. Verificamos si el usuario tiene el rol exacto que pide la ruta
        if ($user->role === $role) {
            return $next($request);
        }

        // 4. Si es Recepción intentando entrar a Admin, o Estilista intentando entrar a Recepción, ¡BLOQUEADO!
        abort(403, 'ACCESO RESTRINGIDO: Tu perfil de ' . strtoupper($user->role) . ' no tiene autorización para entrar aquí.');
    }
}
