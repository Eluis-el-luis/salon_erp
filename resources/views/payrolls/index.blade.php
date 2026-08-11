@extends('layouts.app')

@section('content')
<div class="space-y-6 max-w-7xl mx-auto">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900">Nómina Maestra</h2>
            <p class="text-sm text-gray-500 mt-1">Cálculo automático de salarios, comisiones y deducciones.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded shadow-sm mb-6 font-bold">
            ✓ {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- FORMULARIO DE GENERACIÓN -->
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
            <h3 class="text-lg font-black text-gray-800 border-b pb-2 mb-4">Generar Nueva Nómina</h3>
            
            <form action="{{ url('/nomina/generar') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Empleado / Estilista</label>
                    <select name="user_id" required class="w-full shadow-sm border border-gray-300 rounded-md py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Seleccione un colaborador...</option>
                        @foreach($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->name }} ({{ ucfirst($employee->role) }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Fecha de Inicio</label>
                    <input type="date" name="start_date" required class="w-full shadow-sm border border-gray-300 rounded-md py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Fecha de Corte</label>
                    <input type="date" name="end_date" required class="w-full shadow-sm border border-gray-300 rounded-md py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-lg shadow transition mt-4">
                    Calcular y Generar
                </button>
            </form>
        </div>

        <!-- TABLA DE HISTORIAL -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h3 class="text-lg font-black text-gray-800">Historial de Pagos</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-white">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Colaborador</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Período</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Total a Pagar</th>
                            <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Estado</th>
                            <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($payrolls as $payroll)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900">{{ $payroll->user->name }}</div>
                                <div class="text-xs text-gray-500">Base: C$ {{ number_format($payroll->active_salary, 2) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium">
                                {{ \Carbon\Carbon::parse($payroll->start_date)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($payroll->end_date)->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="text-sm font-black text-emerald-600">C$ {{ number_format($payroll->total_to_pay, 2) }}</div>
                                <div class="text-xs text-red-500">Adelantos: -C$ {{ number_format($payroll->salary_advances, 2) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                @if($payroll->status == 'borrador')
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-bold rounded-full bg-amber-100 text-amber-800">Borrador</span>
                                @else
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-bold rounded-full bg-green-100 text-green-800">Pagado</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <a href="{{ url('/nomina/'.$payroll->id.'/ticket') }}" target="_blank" class="text-emerald-600 hover:text-emerald-900 flex justify-center items-center font-bold transition">
                                    <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                    Colilla
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-gray-500 font-medium text-sm">
                                No se han generado nóminas todavía.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
@endsection