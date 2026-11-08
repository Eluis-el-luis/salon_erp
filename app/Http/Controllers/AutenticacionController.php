<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AutenticacionController extends Controller
{
    // Mostrar el formulario de Login
    public function mostrarFormularioLogin()
    {
        // Si el usuario ya inició sesión, lo mandamos al dashboard
        if (Auth::check()) {
            return redirect('/');
        }
        return view('auth.login');
    }

    // Procesar las credenciales
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            
            // Redirigir según rol: Dashboard solo para alvaro/admin/contador, POS para recepcion/caja
            $rol = auth()->user()->role;
            $redirectTo = in_array($rol, ['alvaro', 'admin', 'contador']) ? '/' : '/pos';
            
            return redirect()->intended($redirectTo);
        }

        // Si falla, lo devolvemos con un mensaje de error
        return back()->withErrors([
            'email' => 'Las credenciales no coinciden con nuestros registros.',
        ])->onlyInput('email');
    }

    // Cerrar sesión
    public function logout(Request $request)
    {
        // Verificar si el usuario tiene una caja abierta antes de cerrar sesión
        $userId = auth()->id();
        $cajaAbierta = \App\Models\SesionCaja::where('user_id', $userId)
            ->where('estado', 'abierta')
            ->exists();

        // Si hay caja abierta, BLOQUEAR el logout y redirigir de vuelta con error
        if ($cajaAbierta) {
            return redirect()->back()->withErrors([
                'error' => 'Tienes una caja abierta. Debes cerrar tu turno (arqueo) antes de cerrar sesión.'
            ]);
        }

        // Cerramos la sesión solo si no hay caja abierta
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}