@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="page-title">Dashboard de Recursos Humanos</h2>
            <p class="page-subtitle">Ciclo de vida del personal, ausencias, asistencia y costo laboral. Período: {{ $mes->locale('es')->isoFormat('MMMM YYYY') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="/nomina/wizard" class="btn btn-primary">⚡ Procesar Nómina</a>
            <a href="/ausencias" class="btn btn-secondary">Gestionar Ausencias</a>
        </div>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded shadow-sm font-bold">✓ {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-sm font-bold">{{ $errors->first() }}</div>
    @endif

    {{-- KPIs --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
        @php
            $kpisUI = [
                ['label' => 'Colaboradores activos', 'value' => number_format($kpis['activos']), 'color' => 'emerald'],
                ['label' => 'Ausentes hoy', 'value' => number_format($kpis['en_ausencia_hoy']), 'color' => 'amber'],
                ['label' => 'Solicitudes pendientes', 'value' => number_format($kpis['solicitudes_pendientes']), 'color' => 'orange'],
                ['label' => 'Tardanzas (mes)', 'value' => number_format($kpis['tardanzas_mes']), 'color' => 'red'],
                ['label' => 'Horas extra (mes)', 'value' => number_format($kpis['horas_extras_mes'], 1) . ' h', 'color' => 'blue'],
                ['label' => 'Propinas en custodia', 'value' => 'C$ ' . number_format($kpis['propinas_pendientes'], 2), 'color' => 'purple'],
                ['label' => 'Costo laboral pagado (mes)', 'value' => 'C$ ' . number_format($kpis['costo_laboral_mes'], 2), 'color' => 'emerald'],
            ];
        @endphp
        @foreach($kpisUI as $kpi)
            <div class="card p-4">
                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">{{ $kpi['label'] }}</p>
                <p class="text-xl font-black mt-1 text-{{ $kpi['color'] }}-600">{{ $kpi['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Calendario del mes --}}
        <div class="card lg:col-span-2 overflow-hidden">
            <div class="card-header"><h3 class="font-black text-gray-800">Calendario de Ausencias</h3></div>
            <div class="p-4">
                @php
                    $diasMes = [];
                    $primerDia = $mes->copy()->startOfMonth();
                    $offset = $primerDia->dayOfWeek; // 0 = domingo
                    $totalDias = $mes->daysInMonth;
                @endphp
                <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-black text-gray-400 uppercase mb-2">
                    <span>Dom</span><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span>
                </div>
                <div class="grid grid-cols-7 gap-1">
                    @for($i = 0; $i < $offset; $i++) <div></div> @endfor
                    @for($d = 1; $d <= $totalDias; $d++)
                        @php
                            $fecha = $mes->copy()->day($d)->toDateString();
                            $ausenciasDia = $ausenciasMes->filter(fn ($a) => $fecha >= $a->fecha_inicio->toDateString() && $fecha <= $a->fecha_fin->toDateString());
                            $esHoy = $fecha === now()->toDateString();
                        @endphp
                        <div class="min-h-[52px] rounded border p-1 text-left {{ $esHoy ? 'border-emerald-500 border-2' : 'border-gray-200' }} {{ $ausenciasDia->isNotEmpty() ? 'bg-amber-50' : '' }}">
                            <span class="text-[10px] font-bold text-gray-500">{{ $d }}</span>
                            @foreach($ausenciasDia->take(2) as $a)
                                <p class="text-[9px] leading-tight truncate font-bold {{ $a->estado === 'aprobada' ? 'text-emerald-700' : 'text-amber-600' }}" title="{{ $a->empleado->nombre }} - {{ $a->tipo->nombre }}">
                                    {{ $a->empleado->nombre }}
                                </p>
                            @endforeach
                            @if($ausenciasDia->count() > 2)
                                <p class="text-[9px] text-gray-400 font-bold">+{{ $ausenciasDia->count() - 2 }} más</p>
                            @endif
                        </div>
                    @endfor
                </div>
                <div class="flex gap-4 mt-3 text-[11px] text-gray-500 font-bold">
                    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-amber-50 border border-amber-300 inline-block"></span> Ausencias aprobadas/pendientes</span>
                    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded border-2 border-emerald-500 inline-block"></span> Hoy</span>
                </div>
            </div>
        </div>

        {{-- Próximas ausencias --}}
        <div class="card overflow-hidden">
            <div class="card-header"><h3 class="font-black text-gray-800">Próximas Ausencias (7 días)</h3></div>
            <div class="divide-y divide-gray-100 max-h-80 overflow-y-auto">
                @forelse($ausenciasProximas as $ausencia)
                    <div class="p-3 flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-bold text-gray-800">{{ $ausencia->empleado->nombre }}</p>
                            <p class="text-xs text-gray-500">{{ $ausencia->tipo->nombre }} · {{ $ausencia->fecha_inicio->format('d/m') }} → {{ $ausencia->fecha_fin->format('d/m') }} ({{ $ausencia->dias }} d)</p>
                        </div>
                        <span class="text-[10px] font-black px-2 py-1 rounded-full {{ $ausencia->tipo->pagado ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">
                            {{ $ausencia->tipo->pagado ? 'CON GOCE' : 'SIN GOCE' }}
                        </span>
                    </div>
                @empty
                    <p class="p-6 text-center text-sm text-gray-500">Sin ausencias próximas programadas.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Tardanzas del mes --}}
    <div class="card overflow-hidden">
        <div class="card-header"><h3 class="font-black text-gray-800">Llegadas Tardías del Mes</h3><p class="text-xs text-gray-500">Incidencias fuera de la tolerancia del turno. Afectan la colilla según la estructura salarial.</p></div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Código</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Incidencias</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Minutos acumulados</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Perfil</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($tardanzasPorEmpleado as $t)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-xs font-black text-gray-400">{{ $t->codigo_empleado }}</td>
                        <td class="px-6 py-3 text-sm font-bold text-gray-800">{{ $t->name }}</td>
                        <td class="px-6 py-3 text-center text-sm font-black text-red-600">{{ $t->incidencias }}</td>
                        <td class="px-6 py-3 text-center text-sm text-gray-600">{{ number_format((int) $t->minutos) }} min</td>
                        <td class="px-6 py-3 text-right"><a href="/rrhh/colaborador/{{ $t->employee_id }}" class="text-emerald-600 font-bold text-sm hover:underline">Ver Perfil 360 →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">Sin llegadas tardías este mes. 🎉</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
