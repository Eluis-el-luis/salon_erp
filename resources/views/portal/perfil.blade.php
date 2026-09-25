@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <div class="card p-6">
        <h2 class="page-title">Mi Perfil</h2>
        <p class="page-subtitle">Información personal y credenciales de acceso.</p>
    </div>

    <div class="card p-6">
        <div class="flex items-center gap-6 mb-6">
            <div class="w-24 h-24 rounded-full bg-emerald-100 flex items-center justify-center text-3xl font-bold text-emerald-700">
                {{ strtoupper($user->name[0]) }}
            </div>
            <div>
                <h3 class="text-2xl font-bold text-gray-900">{{ $user->name }}</h3>
                <p class="text-gray-500 mt-1">{{ $user->email }}</p>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 mt-2">
                    {{ ucfirst($user->role) }}
                </span>
            </div>
        </div>

        <div class="border-t border-gray-200 pt-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Información de Acceso</h3>
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <dt class="text-gray-500">Rol</dt>
                <dd class="font-bold text-gray-900 capitalize">{{ $user->role }}</dd>
                
                <dt class="text-gray-500">Teléfono</dt>
                <dd class="font-bold text-gray-900">{{ $user->phone ?? 'No registrado' }}</dd>
                
                <dt class="text-gray-500">Salario Base</dt>
                <dd class="font-bold text-gray-900">C$ {{ number_format($user->salario_fijo, 2) }}</dd>
                
                <dt class="text-gray-500">Comisión Servicios</dt>
                <dd class="font-bold text-gray-900">{{ $user->comision_servicio ?? 0 }}%</dd>
                
                <dt class="text-gray-500">Comisión Productos</dt>
                <dd class="font-bold text-gray-900">{{ $user->comision_producto ?? 0 }}%</dd>
                
                <dt class="text-gray-500">Fecha de Ingreso</dt>
                <dd class="font-bold text-gray-900">{{ $user->created_at ? \Carbon\Carbon::parse($user->created_at)->format('d/m/Y') : 'N/A' }}</dd>
            </dl>
        </div>

        <div class="mt-6 pt-6 border-t border-gray-200">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Cambiar Contraseña</h3>
            <form action="{{ route('portal.perfil.password') }}" method="POST" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Contraseña Actual</label>
                        <input type="password" name="current_password" required class="input">
                    </div>
                    <div>
                        <label class="label">Nueva Contraseña</label>
                        <input type="password" name="password" required class="input" minlength="8">
                    </div>
                    <div>
                        <label class="label">Confirmar Nueva Contraseña</label>
                        <input type="password" name="password_confirmation" required class="input" minlength="8">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Actualizar Contraseña</button>
            </form>
        </div>
    </div>
</div>
@endsection