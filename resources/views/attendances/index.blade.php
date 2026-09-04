@extends('layouts.app')

@section('content')
<div class="space-y-6">
    
    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Control de Asistencia y Evaluación</h2>
            <p class="text-sm text-gray-500">Registra la hora de llegada y evalúa la presentación del personal.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            
            <span class="text-sm font-bold text-emerald-800 bg-emerald-100 border border-emerald-200 px-5 py-2.5 rounded-full shadow-sm capitalize">
                📅 {{ \Carbon\Carbon::now()->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY') }}
            </span>
        </div>
    </div>

    <!-- Tabla Dinámica de Asistencia -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase">Empleado</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase">Hora de Entrada</th>
                    <th scope="col" class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase">Aseo (Limpieza)</th>
                    <th scope="col" class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase">Uniforme Completo</th>
                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase">Acción</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @forelse ($empleados as $empleado)
                    @php
                        // Buscamos si ya tiene un registro guardado el día de hoy
                        $attendance = $empleado->attendances->first();
                        // Damos formato a la hora si existe, si no, lo dejamos en blanco
                        $timeIn = $attendance ? \Carbon\Carbon::parse($attendance->time_in)->format('H:i') : '';
                        $aseo = $attendance ? $attendance->aseo : 1; // Por defecto Sí (1)
                        $uniforme = $attendance ? $attendance->uniforme : 1; // Por defecto Sí (1)
                    @endphp

                    <!-- Fila individual conectada a Alpine.js -->
                    <tr x-data="attendanceRow({ 
                            userId: {{ $empleado->id }}, 
                            timeIn: '{{ $timeIn }}', 
                            aseo: {{ $aseo ? 'true' : 'false' }}, 
                            uniforme: {{ $uniforme ? 'true' : 'false' }},
                            isSaved: {{ $attendance ? 'true' : 'false' }}
                        })" 
                        class="hover:bg-gray-50 transition">
                        
                        <!-- Columna: Nombre y Rol -->
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold text-gray-900">{{ $empleado->name }}</div>
                            <div class="text-xs text-gray-500 uppercase">{{ $empleado->role }}</div>
                        </td>

                        <!-- Columna: Hora de Entrada -->
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="time" x-model="timeIn" @change="isSaved = false"
                                class="shadow-sm border border-gray-300 rounded-md py-1.5 px-3 text-gray-700 font-bold focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                        </td>

                        <!-- Columna: Toggle Aseo -->
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" x-model="aseo" @change="isSaved = false" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                            </label>
                            <span class="ml-2 text-xs font-bold" :class="aseo ? 'text-emerald-600' : 'text-gray-400'" x-text="aseo ? 'SÍ' : 'NO'"></span>
                        </td>

                        <!-- Columna: Toggle Uniforme -->
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" x-model="uniforme" @change="isSaved = false" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-500"></div>
                            </label>
                            <span class="ml-2 text-xs font-bold" :class="uniforme ? 'text-blue-600' : 'text-gray-400'" x-text="uniforme ? 'SÍ' : 'NO'"></span>
                        </td>

                        <!-- Columna: Botón Guardar -->
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
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay empleados activos registrados en el sistema.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
    // Componente individual para cada fila (empleado)
    function attendanceRow(initialData) {
        return {
            userId: initialData.userId,
            timeIn: initialData.timeIn,
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
                            'X-Requested-With': 'XMLHttpRequest', // Nuestro header anti-redirecciones 302
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            user_id: this.userId,
                            time_in: this.timeIn,
                            aseo: this.aseo,
                            uniforme: this.uniforme
                        })
                    });

                    let data = await response.json();

                    if (response.ok) {
                        this.isSaved = true;
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Asistencia registrada correctamente!' }));
                    } else {
                        let errorMessage = 'Error al guardar.';
                        if(data.errors) {
                            errorMessage = Object.values(data.errors)[0][0];
                        } else if(data.message) {
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