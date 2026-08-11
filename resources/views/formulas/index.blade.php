@extends('layouts.app')

@section('content')
<div x-data="formulaManager()" class="space-y-6 max-w-7xl mx-auto">
    
    <!-- Encabezado -->
    <div class="mb-6">
        <h2 class="text-2xl font-extrabold text-gray-900">Recetas de Servicios</h2>
        <p class="text-sm text-gray-500 mt-1">Configura cuánto producto (ml/oz/gr) se consume automáticamente en cada servicio.</p>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded shadow-sm mb-6 font-bold">
            ✓ {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- LISTA DE SERVICIOS (Izquierda) -->
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden col-span-1">
            <div class="px-4 py-3 bg-gray-50 border-b font-black text-gray-700">
                Selecciona un Servicio
            </div>
            <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
                @foreach($services as $service)
                    <div @click="selectService({{ $service }})" 
                         :class="{'bg-emerald-50 border-l-4 border-emerald-500': selectedService && selectedService.id === {{ $service->id }}}"
                         class="p-4 cursor-pointer hover:bg-gray-50 transition flex justify-between items-center">
                        <div>
                            <div class="font-bold text-gray-900">{{ $service->name }}</div>
                            <div class="text-xs text-gray-500">{{ $service->formulas->count() }} ingredientes configurados</div>
                        </div>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- DETALLE DE LA RECETA (Derecha) -->
        <div class="col-span-1 lg:col-span-2">
            
            <!-- Estado Vacío -->
            <div x-show="!selectedService" class="bg-white rounded-xl shadow-md border border-gray-200 p-12 text-center text-gray-500 flex flex-col items-center justify-center h-full">
                <svg class="w-16 h-16 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                <p class="font-bold text-lg">Ningún servicio seleccionado</p>
                <p class="text-sm">Haz clic en un servicio de la lista para ver o editar su receta.</p>
            </div>

            <!-- Editor de Receta -->
            <div x-show="selectedService" style="display: none;" class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 bg-emerald-600 text-white flex justify-between items-center">
                    <h3 class="text-lg font-black" x-text="'Receta para: ' + (selectedService ? selectedService.name : '')"></h3>
                </div>
                
                <!-- Formulario para agregar ingrediente -->
                <div class="p-6 border-b border-gray-100 bg-gray-50">
                    <form action="{{ url('/formulas') }}" method="POST" class="flex items-end space-x-4">
                        @csrf
                        <input type="hidden" name="service_id" :value="selectedService ? selectedService.id : ''">
                        
                        <div class="flex-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Producto (Inventario)</label>
                            <select name="item_id" required class="w-full shadow-sm border border-gray-300 rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                                <option value="">Selecciona un producto...</option>
                                @foreach($fractionableItems as $item)
                                    <option value="{{ $item->id }}">{{ $item->producto }} (Medido en {{ $item->unit_measure }})</option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div class="w-32">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Cantidad a usar</label>
                            <input type="number" step="0.01" name="quantity_used" required min="0.1" placeholder="Ej: 60" class="w-full shadow-sm border border-gray-300 rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>

                        <button type="submit" class="bg-gray-900 hover:bg-gray-800 text-white font-bold py-2 px-4 rounded shadow transition">
                            + Agregar
                        </button>
                    </form>
                </div>

                <!-- Lista de Ingredientes Actuales -->
                <div class="p-0">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-white">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-black text-gray-500 uppercase">Producto</th>
                                <th class="px-6 py-3 text-center text-xs font-black text-gray-500 uppercase">Consumo por Servicio</th>
                                <th class="px-6 py-3 text-right text-xs font-black text-gray-500 uppercase">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white" id="formulas-tbody">
                            <template x-for="formula in (selectedService ? selectedService.formulas : [])" :key="formula.id">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900" x-text="formula.item.producto"></div>
                                        <div class="text-xs text-gray-500" x-text="formula.item.marca"></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <span class="px-3 py-1 inline-flex text-sm leading-5 font-black rounded-full bg-blue-100 text-blue-800" x-text="formula.quantity_used + ' ' + formula.item.unit_measure">
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <!-- Formulario para eliminar -->
                                        <form :action="'/formulas/' + formula.id" method="POST" onsubmit="return confirm('¿Quitar este ingrediente de la receta?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900 font-bold text-sm">Quitar</button>
                                        </form>
                                    </td>
                                </tr>
                            </template>
                            
                            <!-- Mensaje si no hay ingredientes -->
                            <tr x-show="selectedService && selectedService.formulas.length === 0">
                                <td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500">
                                    Este servicio aún no tiene productos asignados a su receta.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function formulaManager() {
        return {
            selectedService: null,
            selectService(service) {
                this.selectedService = service;
            }
        }
    }
</script>
@endsection