<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\ComisionGenerada;
use App\Models\Nomina;
use App\Models\AdelantoCuota;
use App\Models\PortalColaboradorConfig;
use App\Models\PortalColaboradorToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class PortalColaboradorController extends Controller
{
    /**
     * Verificar si el portal está habilitado
     */
    protected function portalHabilitado(): bool
    {
        return (bool) PortalColaboradorConfig::where('clave', 'portal_habilitado')
            ->value('valor') === 'true';
    }

    /**
     * Login del colaborador
     */
    public function showLogin()
    {
        if (!$this->portalHabilitado()) {
            abort(404);
        }
        return view('portal.login');
    }

    public function login(Request $request)
    {
        if (!$this->portalHabilitado()) {
            abort(404);
        }

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = Usuario::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password) || !$user->is_active) {
            return back()->withErrors(['email' => 'Credenciales inválidas'])->withInput($request->only('email'));
        }

        // Generar token de acceso
        $token = PortalColaboradorToken::create([
            'usuario_id' => $user->id,
            'token' => bin2hex(random_bytes(32)),
            'expira_en' => now()->addDays(30),
            'activo' => true,
        ]);

        $request->session()->put('portal_token', $token->token);
        
        return redirect()->route('portal.dashboard');
    }

    public function logout(Request $request)
    {
        $token = $request->session()->pull('portal_token');
        if ($token) {
            PortalColaboradorToken::where('token', $token)->update(['activo' => false]);
        }
        return redirect()->route('portal.login');
    }

    /**
     * Middleware para verificar token de sesión
     */
    protected function autenticarPortal(Request $request)
    {
        $token = $request->session()->get('portal_token');
        if (!$token) {
            return redirect()->route('portal.login');
        }

        $tokenRecord = PortalColaboradorToken::where('token', $token)
            ->where('activo', true)
            ->where('expira_en', '>', now())
            ->first();

        if (!$tokenRecord) {
            $request->session()->forget('portal_token');
            return redirect()->route('portal.login');
        }

        return $tokenRecord->usuario;
    }

    public function dashboard(Request $request)
    {
        $user = $this->autenticarPortal($request);
        if (!$user instanceof Usuario) {
            return $user;
        }

        $diasHistorial = (int) PortalColaboradorConfig::where('clave', 'dias_historial')->value('valor') ?? 90;
        $fechaInicio = now()->subDays($diasHistorial)->startOfDay();

        // Comisiones del período
        $comisiones = ComisionGenerada::where('empleado_id', $user->id)
            ->where('fecha', '>=', $fechaInicio)
            ->orderBy('fecha', 'desc')
            ->get();

        $totalComisiones = $comisiones->sum('monto_comision');
        $pendientes = $comisiones->where('estado', 'pendiente')->sum('monto_comision');
        $pagadas = $comisiones->where('estado', 'pagada')->sum('monto_comision');

        // Nóminas del período
        $nominas = Nomina::where('user_id', $user->id)
            ->whereBetween('created_at', [$fechaInicio, now()])
            ->orderBy('created_at', 'desc')
            ->get();

        // Cuotas de adelantos pendientes
        $cuotasPendientes = \App\Models\AdelantoCuota::whereHas('adelanto', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->whereIn('estado', ['pendiente', 'parcial'])
            ->orderBy('fecha_vencimiento')
            ->get();

        return view('portal.dashboard', compact(
            'user', 'comisiones', 'totalComisiones', 'pendientes', 'pagadas',
            'nominas', 'cuotasPendientes', 'diasHistorial'
        ));
    }

    public function comisiones(Request $request)
    {
        $user = $this->autenticarPortal($request);
        if (!$user instanceof \App\Models\Usuario) {
            return $user;
        }

        $diasHistorial = (int) PortalColaboradorConfig::where('clave', 'dias_historial')->value('valor') ?? 90;
        $fechaInicio = now()->subDays($diasHistorial)->startOfDay();

        $comisiones = ComisionGenerada::where('empleado_id', $user->id)
            ->where('fecha', '>=', $fechaInicio)
            ->orderBy('fecha', 'desc')
            ->paginate(20);

        return view('portal.comisiones', compact('user', 'comisiones'));
    }

    public function nominas(Request $request)
    {
        $user = $this->autenticarPortal($request);
        if (!$user instanceof \App\Models\Usuario) {
            return $user;
        }

        $nominas = Nomina::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('portal.nominas', compact('user', 'nominas'));
    }

    public function adelantos(Request $request)
    {
        $user = $this->autenticarPortal($request);
        if (!$user instanceof \App\Models\Usuario) {
            return $user;
        }

        $cuotas = \App\Models\AdelantoCuota::whereHas('adelanto', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with('adelanto')->orderBy('fecha_vencimiento')->paginate(20);

        return view('portal.adelantos', compact('user', 'cuotas'));
    }

    public function perfil(Request $request)
    {
        $user = $this->autenticarPortal($request);
        if (!$user instanceof \App\Models\Usuario) {
            return $user;
        }

        return view('portal.perfil', compact('user'));
    }
}