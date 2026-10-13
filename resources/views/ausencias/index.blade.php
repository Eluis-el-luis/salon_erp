@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="page-title">Ausencias y Permisos</h2>
        <p class="page-subtitle">Solicita, aprueba o rechaza ausencias. Las aprobadas sin goce se deducen proporcionalmente en la colilla.</p>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded font-bold">✓ {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded font-bold">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Nueva solicitud --}}
        <div class="card p-6">
            <h3 class="font-black text-gray-800 border-b pb-2 mb-4">Nueva Solicitud</h3>
            <form action="/ausencias" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Colaborador</label>
                    <select name="employee_id" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                        <option value="">Seleccione...</option>
                        @foreach($empleados as $empleado)
                            <option value="{{ $empleado->id }}">{{ $empleado->codigo_empleado }} · {{ $empleado->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tipo de ausencia</label>
                    <select name="leave_type_id" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                        <option value="">Seleccione...</option>
                        @foreach($tipos as $tipo)
                            <option value="{{ $tipo->id }}">{{ $tipo->nombre }} ({{ $tipo->pagado ? 'con goce' : 'sin goce' }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Desde</label>
                        <input type="date" name="fecha_inicio" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Hasta</label>
                        <input type="date" name="fecha_fin" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Motivo</label>
                    <textarea name="motivo" rows="2" maxlength="1000" class="w-full border border-gray-300 rounded-md py-2 px-3" placeholder="Opcional"></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-full">Enviar Solicitud</button>
            </form>
        </div>

        {{-- Pendientes de aprobación --}}
        <div class="card lg:col-span-2 overflow-hidden">
            <div class="card-header"><h3 class="font-black text-gray-800">Solicitudes Pendientes</h3></div>
            <div class="divide-y divide-gray-100">
                @forelse($pendientes as $ausencia)
                    <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <p class="text-sm font-black text-gray-800">{{ $ausencia->empleado->nombre }}
                                <span class="ml-1 px-2 py-0.5 text-[10px] font-black rounded-full {{ $ausencia->tipo->pagado ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ $ausencia->tipo->pagado ? 'CON GOCE' : 'SIN GOCE' }}</span>
                            </p>
                            <p class="text-xs text-gray-500">{{ $ausencia->tipo->nombre }} · {{ $ausencia->fecha_inicio->format('d/m/Y') }} → {{ $ausencia->fecha_fin->format('d/m/Y') }} ({{ $ausencia->dias }} días)</p>
                            @if($ausencia->motivo) <p class="text-xs text-gray-400 italic mt-1">"{{ Str::limit($ausencia->motivo, 120) }}"</p> @endif
                        </div>
                        <div class="flex gap-2">
                            <form action="/ausencias/{{ $ausencia->id }}/aprobar" method="POST">
                                @csrf
                                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs py-2 px-4 rounded-lg">✓ Aprobar</button>
                            </form>
                            <form action="/ausencias/{{ $ausencia->id }}/rechazar" method="POST">
                                @csrf
                                <button type="submit" class="bg-red-100 hover:bg-red-200 text-red-700 font-bold text-xs py-2 px-4 rounded-lg">✕ Rechazar</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="p-8 text-center text-sm text-gray-500">No hay solicitudes pendientes de aprobación.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Calendario del mes --}}
    <div class="card overflow-hidden">
        <div class="card-header flex items-center justify-between">
            <h3 class="font-black text-gray-800">Calendario — {{ $mes->locale('es')->isoFormat('MMMM YYYY') }}</h3>
            <div class="flex gap-2">
                <a href="/ausencias?mes={{ $mes->copy()->subMonth()->format('Y-m') }}" class="text-sm font-bold text-gray-500 hover:text-gray-800">← Mes anterior</a>
                <a href="/ausencias?mes={{ $mes->copy()->addMonth()->format('Y-m') }}" class="text-sm font-bold text-gray-500 hover:text-gray-800">Mes siguiente →</a>
            </div>
        </div>
        <div class="p-4">
            @php
                $offset = $mes->copy()->startOfMonth()->dayOfWeek;
            @endphp
            <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-black text-gray-400 uppercase mb-2">
                <span>Dom</span><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span>
            </div>
            <div class="grid grid-cols-7 gap-1">
                @for($i = 0; $i < $offset; $i++) <div></div> @endfor
                @for($d = 1; $d <= $mes->daysInMonth; $d++)
                    @php
                        $fecha = $mes->copy()->day($d)->toDateString();
                        $ausenciasDia = $ausenciasMes->filter(fn ($a) => $fecha >= $a->fecha_inicio->toDateString() && $fecha <= $a->fecha_fin->toDateString());
                    @endphp
                    <div class="min-h-[56px] rounded border p-1 {{ $fecha === now()->toDateString() ? 'border-emerald-500 border-2' : 'border-gray-200' }} {{ $ausenciasDia->isNotEmpty() ? 'bg-amber-50' : '' }}">
                        <span class="text-[10px] font-bold text-gray-500">{{ $d }}</span>
                        @foreach($ausenciasDia->take(3) as $a)
                            <p class="text-[9px] leading-tight truncate font-bold {{ $a->estado === 'aprobada' ? 'text-emerald-700' : 'text-amber-600' }}" title="{{ $a->empleado->nombre }} ({{ $a->estado }})">{{ $a->empleado->nombre }}</p>
                        @endforeach
                    </div>
                @endfor
            </div>
        </div>
    </div>

    {{-- Historial procesado --}}
    <div class="card overflow-hidden">
        <div class="card-header"><h3 class="font-black text-gray-800">Historial Reciente</h3></div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Tipo</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Período</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Acción</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($procesadas as $ausencia)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm font-bold text-gray-800">{{ $ausencia->empleado->nombre }}</td>
                        <td class="px-6 py-3 text-sm text-gray-600">{{ $ausencia->tipo->nombre }}</td>
                        <td class="px-6 py-3 text-sm text-gray-600">{{ $ausencia->fecha_inicio->format('d/m/Y') }} → {{ $ausencia->fecha_fin->format('d/m/Y') }}</td>
                        <td class="px-6 py-3 text-center">
                            <span class="px-2 py-1 text-[10px] font-black rounded-full uppercase {{ $ausencia->estado === 'aprobada' ? 'bg-emerald-100 text-emerald-700' : ($ausencia->estado === 'rechazada' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-500') }}">{{ $ausencia->estado }}</span>
                        </td>
                        <td class="px-6 py-3 text-center">
                            @if(in_array($ausencia->estado, ['pendiente', 'aprobada']))
                                <form action="/ausencias/{{ $ausencia->id }}/cancelar" method="POST">
                                    @csrf
                                    <button type="submit" class="text-xs font-bold text-gray-500 hover:text-red-600">Cancelar</button>
                                </form>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">Sin historial de ausencias.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
