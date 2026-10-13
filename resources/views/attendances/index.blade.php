@extends('layouts.app')

@section('content')
<div class="space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="page-title">Control de Asistencia y Evaluación</h2>
            <p class="page-subtitle">Registro de entrada/salida contra el turno vigente, con cálculo de tardanzas y horas extra.</p>
        </div>
        <span class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-800 bg-emerald-50 border border-emerald-200 px-4 py-2 rounded-full capitalize">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            {{ \Carbon\Carbon::parse($hoy)->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY') }}
        </span>
    </div>

    <!-- Tabla Dinámica de Asistencia -->
    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase">Empleado</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase">Turno Esperado</th>
                    <th scope="col" class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase">Entrada</th>
                    <th scope="col" class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase">Tardanza</th>
                    <th scope="col" class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase">Salida</th>
                    <th scope="col" class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase">Aseo</th>
                    <th scope="col" class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase">Uniforme</th>
                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase">Acción</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @forelse ($empleados as $empleado)
                    @php
                        $a = $empleado->asistencia_hoy;
                        $turno = $empleado->turno_actual;
                        $timeIn = $a ? \Carbon\Carbon::parse($a->time_in)->format('H:i') : '';
                        $timeOut = ($a && $a->time_out) ? \Carbon\Carbon::parse($a->time_out)->format('H:i') : '';
                        $tardanza = $a ? (int) $a->minutos_tardanza : 0;
                        $aseo = $a ? $a->aseo : 1;
                        $uniforme = $a ? $a->uniforme : 1;
                    @endphp

                    <tr x-data="attendanceRow({
                            userId: {{ $empleado->user_id }},
                            timeIn: '{{ $timeIn }}',
                            timeOut: '{{ $timeOut }}',
                            aseo: {{ $aseo ? 'true' : 'false' }},
                            uniforme: {{ $uniforme ? 'true' : 'false' }},
                            isSaved: {{ $a ? 'true' : 'false' }}
                        })"
                        class="hover:bg-gray-50 transition">

                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold text-gray-900">{{ $empleado->nombre }}</div>
                            <div class="text-xs text-gray-400">{{ $empleado->codigo_empleado }} · {{ ucfirst($empleado->usuario->role ?? '') }}</div>
                        </td>

                        <!-- Turno esperado -->
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if($turno)
                                <span class="font-bold text-gray-700">{{ substr($turno->hora_entrada, 0, 5) }} – {{ substr($turno->hora_salida, 0, 5) }}</span>
                                <span class="block text-[10px] text-gray-400">{{ $turno->nombre }} · tolerancia {{ $turno->tolerancia_minutos }} min</span>
                            @else
                                <span class="text-amber-600 font-bold text-xs">Sin turno asignado</span>
                            @endif
                        </td>

                        <!-- Hora de Entrada -->
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="time" x-model="timeIn" @change="isSaved = false"
                                class="border border-gray-300 rounded-md py-1.5 px-3 font-bold focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                        </td>

                        <!-- Tardanza -->
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            @if($a && $tardanza > 0)
                                <span class="px-2 py-1 text-[10px] font-black rounded-full bg-red-100 text-red-700">+{{ $tardanza }} min</span>
                            @elseif($a)
                                <span class="px-2 py-1 text-[10px] font-black rounded-full bg-emerald-100 text-emerald-700">Puntual</span>
                            @else
                                <span class="text-gray-300 text-xs">—</span>
                            @endif
                        </td>

                        <!-- Hora de Salida -->
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="time" x-model="timeOut" @change="isSaved = false"
                                class="border border-gray-300 rounded-md py-1.5 px-3 font-bold focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </td>

                        <!-- Aseo -->
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" x-model="aseo" @change="isSaved = false" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                            </label>
                            <span class="ml-2 text-xs font-bold" :class="aseo ? 'text-emerald-600' : 'text-gray-400'" x-text="aseo ? 'SÍ' : 'NO'"></span>
                        </td>

                        <!-- Uniforme -->
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" x-model="uniforme" @change="isSaved = false" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-500"></div>
                            </label>
                            <span class="ml-2 text-xs font-bold" :class="uniforme ? 'text-blue-600' : 'text-gray-400'" x-text="uniforme ? 'SÍ' : 'NO'"></span>
                        </td>

                        <!-- Guardar -->
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <button @click="saveAttendance()"
                                    :class="isSaved ? 'bg-gray-100 text-gray-500 border-gray-200 hover:bg-gray-200' : 'bg-emerald-600 text-white shadow-sm hover:bg-emerald-700'"
                                    class="border font-bold py-1.5 px-4 rounded transition text-sm flex items-center justify-end ml-auto">
                                <span x-show="!isSaved">Guardar</span>
                                <span x-show="isSaved">
                                    <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    Guardado
                                </span>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay empleados activos registrados en el sistema.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Resumen del mes -->
    <div class="card overflow-hidden">
        <div class="card-header"><h3 class="font-black text-gray-800">Resumen del Mes</h3></div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Días registrados</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Tardanzas</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Minutos</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Horas extra</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Días de ausencia</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Perfil</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($resumenMes as $fila)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm font-bold text-gray-800">{{ $fila['empleado']->nombre }}</td>
                        <td class="px-6 py-3 text-center text-sm">{{ $fila['dias'] }}</td>
                        <td class="px-6 py-3 text-center text-sm font-black {{ $fila['tardanzas'] > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $fila['tardanzas'] }}</td>
                        <td class="px-6 py-3 text-center text-sm text-gray-600">{{ $fila['minutos_tardanza'] }}</td>
                        <td class="px-6 py-3 text-center text-sm font-bold text-blue-600">{{ $fila['horas_extras'] }} h</td>
                        <td class="px-6 py-3 text-center text-sm text-amber-600">{{ $fila['ausencias'] }}</td>
                        <td class="px-6 py-3 text-right"><a href="/rrhh/colaborador/{{ $fila['empleado']->id }}" class="text-emerald-600 font-bold text-sm hover:underline">Ver →</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script>
    function attendanceRow(initialData) {
        return {
            userId: initialData.userId,
            timeIn: initialData.timeIn,
            timeOut: initialData.timeOut,
            aseo: initialData.aseo,
            uniforme: initialData.uniforme,
            isSaved: initialData.isSaved,

            async saveAttendance() {
                if (!this.timeIn) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Debes ingresar la hora de entrada.' }));
                    return;
                }

                try {
                    let response = await fetch('/asistencia', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            user_id: this.userId,
                            time_in: this.timeIn,
                            time_out: this.timeOut || null,
                            aseo: this.aseo,
                            uniforme: this.uniforme
                        })
                    });

                    let data = await response.json();

                    if (response.ok) {
                        this.isSaved = true;
                        window.dispatchEvent(new CustomEvent('notify', { detail: data.message }));

                        // Si hay hora de salida, registrarla también
                        if (this.timeOut) {
                            let respSalida = await fetch('/asistencia/salida', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({ user_id: this.userId, time_out: this.timeOut })
                            });
                            let dataSalida = await respSalida.json();
                            if (respSalida.ok) {
                                window.dispatchEvent(new CustomEvent('notify', { detail: dataSalida.message }));
                            }
                        }
                    } else {
                        let errorMessage = 'Error al guardar.';
                        if (data.errors) {
                            errorMessage = Object.values(data.errors)[0][0];
                        } else if (data.message) {
                            errorMessage = data.message;
                        }
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    console.error(error);
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión con el servidor.' }));
                }
            }
        }
    }
</script>
@endsection
