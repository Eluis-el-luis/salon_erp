<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AutenticacionController extends Controller
{
    // Mostrar el formulario de Login
    public function mostrarFormularioLogin()
    {
        // Si el usuario ya inició sesión, lo mandamos a su página según rol
        if (Auth::check()) {
            $rol = auth()->user()->role;
            $redirectTo = match ($rol) {
                'alvaro', 'contador' => '/',
                'admin' => '/bancos',
                'recepcion' => '/pos',
                'estilista' => '/agenda',
                default => '/pos',
            };
            return redirect($redirectTo);
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
            
            // Redirigir según rol a una página que SÍ pueda acceder:
            // - alvaro, contador -> Dashboard (/)
            // - admin -> Bancos/Reportes (/bancos)
            // - recepcion -> POS (/pos)
            // - estilista -> Agenda (/agenda)
            $rol = auth()->user()->role;
            $redirectTo = match ($rol) {
                'alvaro', 'contador' => '/',
                'admin' => '/bancos',
                'recepcion' => '/pos',
                'estilista' => '/agenda',
                default => '/pos',
            };

            // NO usar intended() para evitar que roles sin acceso al dashboard
            // sean redirigidos a / por una URL "intencionada" previa.
            return redirect($redirectTo);
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