@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="page-title">Turnos y Asignaciones</h2>
        <p class="page-subtitle">Define horarios con tolerancia y asígnalos al personal. La tardanza se calcula fuera de tolerancia.</p>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded font-bold">✓ {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded font-bold">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Crear / editar turno --}}
        <div class="card p-6" x-data="{ editando: {{ $turnos->isNotEmpty() ? 'null' : 'null' }} }">
            <h3 class="font-black text-gray-800 border-b pb-2 mb-4">Nuevo Turno</h3>
            <form action="/rrhh/turnos" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Nombre</label>
                    <input type="text" name="nombre" required maxlength="120" placeholder="Ej. Turno Fin de Semana" class="w-full border border-gray-300 rounded-md py-2 px-3">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Entrada</label>
                        <input type="time" name="hora_entrada" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Salida</label>
                        <input type="time" name="hora_salida" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tolerancia (minutos)</label>
                    <input type="number" name="tolerancia_minutos" required min="0" max="240" value="10" class="w-full border border-gray-300 rounded-md py-2 px-3">
                </div>
                <button type="submit" class="btn btn-primary w-full">Crear Turno</button>
            </form>
        </div>

        {{-- Catálogo de turnos --}}
        <div class="card lg:col-span-2 overflow-hidden">
            <div class="card-header"><h3 class="font-black text-gray-800">Catálogo de Turnos</h3></div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">Turno</th>
                        <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">Horario</th>
                        <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">Tolerancia</th>
                        <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">Personal</th>
                        <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($turnos as $turno)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-bold text-gray-800">{{ $turno->nombre }}</td>
                            <td class="px-4 py-3 text-center text-sm">{{ substr($turno->hora_entrada, 0, 5) }} – {{ substr($turno->hora_salida, 0, 5) }}</td>
                            <td class="px-4 py-3 text-center text-sm">{{ $turno->tolerancia_minutos }} min</td>
                            <td class="px-4 py-3 text-center text-sm font-black text-gray-700">{{ $turno->asignaciones_count }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-1 text-[10px] font-black rounded-full {{ $turno->activo ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $turno->activo ? 'Activo' : 'Inactivo' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">No hay turnos definidos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Asignación de turnos (Shift Roster) --}}
    <div class="card overflow-hidden">
        <div class="card-header"><h3 class="font-black text-gray-800">Asignación Vigente por Colaborador</h3></div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Código</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Turno actual</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Reasignar turno</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($empleados as $empleado)
                    <tr class="hover:bg-gray-50" x-data="{ abierta: false }">
                        <td class="px-6 py-3 text-xs font-black text-gray-400">{{ $empleado->codigo_empleado }}</td>
                        <td class="px-6 py-3 text-sm font-bold text-gray-800">{{ $empleado->nombre }}</td>
                        <td class="px-6 py-3 text-sm">
                            @if($empleado->turno_actual)
                                <span class="font-bold text-emerald-700">{{ $empleado->turno_actual->nombre }}</span>
                                <span class="text-gray-400 text-xs">( {{ substr($empleado->turno_actual->hora_entrada, 0, 5) }} – {{ substr($empleado->turno_actual->hora_salida, 0, 5) }} )</span>
                            @else
                                <span class="text-amber-600 font-bold text-xs">Sin turno</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-right">
                            <button @click="abierta = !abierta" class="text-blue-600 font-bold text-sm hover:underline">@{{ abierta ? 'Cerrar' : 'Asignar' }}</button>
                            <div x-show="abierta" x-cloak class="mt-3 flex justify-end gap-2">
                                <form action="/rrhh/turnos/asignar" method="POST" class="flex gap-2 items-center">
                                    @csrf
                                    <input type="hidden" name="employee_id" value="{{ $empleado->id }}">
                                    <select name="shift_id" required class="border border-gray-300 rounded-md py-1.5 px-2 text-sm">
                                        <option value="">Turno...</option>
                                        @foreach($turnos->where('activo', true) as $turno)
                                            <option value="{{ $turno->id }}">{{ $turno->nombre }}</option>
                                        @endforeach
                                    </select>
                                    <input type="date" name="vigente_desde" value="{{ now()->toDateString() }}" required class="border border-gray-300 rounded-md py-1.5 px-2 text-sm">
                                    <button type="submit" class="bg-emerald-600 text-white font-bold py-1.5 px-3 rounded text-sm hover:bg-emerald-700">Guardar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">No hay colaboradores activos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
