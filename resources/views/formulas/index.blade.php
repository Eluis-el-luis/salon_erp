@extends('layouts.app')

@section('content')
<div x-data="formulaManager()" class="space-y-6 max-w-7xl mx-auto">

    <!-- Encabezado -->
    <div class="mb-6">
        <h2 class="page-title">Recetas de Servicios (BOM)</h2>
        <p class="page-subtitle">Define el consumo <strong>estándar</strong> de insumos por servicio. El costo se calcula al precio de compra actual (lotes) para conocer tu margen bruto; el consumo <strong>real</strong> se captura en el POS al facturar.</p>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 p-4 rounded-lg shadow-sm mb-6 font-bold">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- LISTA DE SERVICIOS (Izquierda) -->
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden col-span-1">
            <div class="px-4 py-3 bg-gray-50 border-b font-black text-gray-700">
                Selecciona un Servicio
            </div>
            <div class="divide-y divide-gray-100 max-h-[640px] overflow-y-auto">
                @foreach($servicios as $servicio)
                    @php
                        $costo = $servicio->costoInsumos();
                        $margenPct = $servicio->margenPorcentaje();
                    @endphp
                    <div @click="selectService({{ $servicio->id }})"
                         :class="{'bg-emerald-50 border-l-4 border-emerald-500': selectedId === {{ $servicio->id }}}"
                         class="p-4 cursor-pointer hover:bg-gray-50 transition flex justify-between items-center">
                        <div>
                            <div class="font-bold text-gray-900">{{ $servicio->name }}</div>
                            <div class="text-xs text-gray-500">{{ $servicio->formulas->count() }} insumos ·
                                @if ($costo > 0)
                                    Costo C$ {{ number_format($costo, 2) }}
                                @else
                                    <span class="italic">sin costo registrado</span>
                                @endif
                            </div>
                        </div>
                        @if ($margenPct !== null)
                            <span class="text-[10px] font-black px-2 py-1 rounded-full {{ $margenPct >= 50 ? 'bg-emerald-100 text-emerald-700' : ($margenPct >= 20 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700') }}">
                                {{ number_format($margenPct, 0) }}%
                            </span>
                        @endif
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
                <p class="text-sm">Haz clic en un servicio de la lista para ver o editar su receta estándar.</p>
            </div>

            <!-- Editor de Receta -->
            <div x-show="selectedService" style="display: none;" class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 bg-emerald-600 text-white flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-black" x-text="'Receta estándar: ' + (selectedService ? selectedService.name : '')"></h3>
                        <p class="text-emerald-100 text-xs" x-text="'Precio de venta: C$ ' + parseFloat(selectedService ? selectedService.price : 0).toFixed(2)"></p>
                    </div>
                </div>

                <!-- Formulario para agregar ingrediente -->
                <div class="p-6 border-b border-gray-100 bg-gray-50">
                    <form @submit.prevent="agregarIngrediente()" class="flex items-end space-x-4">
                        <input type="hidden" x-model="nuevo.service_id">

                        <div class="flex-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Producto (Inventario)</label>
                            <select x-model.number="nuevo.item_id" required class="w-full shadow-sm border border-gray-300 rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                                <option value="">Selecciona un producto...</option>
                                <template x-for="articulo in articulos" :key="articulo.id">
                                    <option :value="articulo.id" x-text="articulo.producto + ' (Medido en ' + articulo.unit_measure + ')'"></option>
                                </template>
                            </select>
                        </div>

                        <div class="w-32">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Cantidad a usar</label>
                            <input type="number" step="0.01" x-model.number="nuevo.quantity_used" required min="0.1" placeholder="Ej: 60" class="w-full shadow-sm border border-gray-300 rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>

                        <button type="submit" class="bg-gray-900 hover:bg-gray-800 text-white font-bold py-2 px-4 rounded shadow transition">
                            + Agregar
                        </button>
                    </form>
                </div>

                <!-- Lista de Ingredientes con COSTO DINÁMICO -->
                <div class="p-0">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-white">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-black text-gray-500 uppercase">Producto</th>
                                <th class="px-6 py-3 text-center text-xs font-black text-gray-500 uppercase">Consumo Estándar</th>
                                <th class="px-6 py-3 text-right text-xs font-black text-gray-500 uppercase">Costo x Medida</th>
                                <th class="px-6 py-3 text-right text-xs font-black text-gray-500 uppercase">Costo Línea</th>
                                <th class="px-6 py-3 text-right text-xs font-black text-gray-500 uppercase">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white" id="formulas-tbody">
                            <template x-for="formula in (selectedService ? selectedService.formulas : [])" :key="formula.id">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900" x-text="formula.articulo.producto"></div>
                                        <div class="text-xs text-gray-500" x-text="formula.articulo.marca"></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <input type="number" step="0.01" min="0.01" x-model.number="formula.quantity_used"
                                                   class="w-20 text-center shadow-sm border border-gray-300 rounded py-1 px-1 text-sm font-bold text-blue-800">
                                            <span class="text-xs text-gray-500" x-text="formula.articulo.unit_measure || 'ml'"></span>
                                            <button type="button" @click="guardarCantidad(formula)" title="Guardar consumo estándar"
                                                    class="text-emerald-600 hover:text-emerald-800 font-black text-sm">&check;</button>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-xs text-gray-600">
                                        <span x-show="costoPorMedida(formula) !== null" x-text="'C$ ' + parseFloat(costoPorMedida(formula)).toFixed(4)"></span>
                                        <span x-show="costoPorMedida(formula) === null" class="italic text-gray-400" title="Sin lotes con costo registrado">sin costo</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                                        <span x-show="costoLinea(formula) > 0" x-text="'C$ ' + parseFloat(costoLinea(formula)).toFixed(2)"></span>
                                        <span x-show="!(costoLinea(formula) > 0)" class="italic text-gray-400">—</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <button type="button" @click="quitarIngrediente(formula)" class="text-red-600 hover:text-red-900 font-bold text-sm">Quitar</button>
                                    </td>
                                </tr>
                            </template>

                            <!-- Mensaje si no hay ingredientes -->
                            <tr x-show="selectedService && selectedService.formulas.length === 0">
                                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                                    Este servicio aún no tiene productos asignados a su receta.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- RESUMEN DE COSTOS Y MARGEN -->
                <div class="p-6 bg-gray-50 border-t border-gray-200">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
                            <div class="text-[10px] font-black text-gray-500 uppercase">Costo de Insumos (estimado)</div>
                            <div class="text-xl font-black text-gray-900 mt-1" x-text="'C$ ' + totalCosto().toFixed(2)"></div>
                            <p class="text-[10px] text-gray-400 mt-1" x-text="'Basado en el costo de compra actual de los lotes'"></p>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
                            <div class="text-[10px] font-black text-gray-500 uppercase">Precio de Venta</div>
                            <div class="text-xl font-black text-emerald-600 mt-1" x-text="'C$ ' + parseFloat(selectedService ? selectedService.price : 0).toFixed(2)"></div>
                        </div>
                        <div class="rounded-xl border p-4 text-center" :class="totalMargen() >= 0 ? 'bg-white border-gray-200' : 'bg-red-50 border-red-200'">
                            <div class="text-[10px] font-black text-gray-500 uppercase">Margen Bruto Estimado</div>
                            <div class="text-xl font-black mt-1" :class="totalMargen() >= 0 ? 'text-gray-900' : 'text-red-600'"
                                 x-text="'C$ ' + totalMargen().toFixed(2) + (totalMargenPct() !== null ? ' (' + totalMargenPct() + '%)' : '')"></div>
                            <p x-show="totalCosto() === 0" class="text-[10px] text-amber-600 mt-1 font-bold">
                                Registra lotes con costo en inventario para valorar la receta.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function formulaManager() {
        return {
            selectedId: null,
            selectedService: null,
            articulos: @json($articulosFraccionables),
            // Costo de compra actual por insumo: costo_unitario (por frasco/unidad),
            // costo_por_medida (por ml/oz) y fuente (lotes/ultimo_lote/sin_costo)
            costos: @json($costos),

            nuevo: { service_id: '', item_id: '', quantity_used: '' },

            selectService(serviceId) {
                this.selectedId = serviceId;
                // Refrescar contra los datos embebidos (ya incluyen formulas.articulo)
                this.selectedService = serviciosData.find(function (s) { return s.id === serviceId; });
                this.nuevo.service_id = serviceId;
                this.nuevo.item_id = '';
                this.nuevo.quantity_used = '';
            },

            costoPorMedida(formula) {
                let info = this.costos[formula.item_id];
                return info ? info.costo_por_medida : null;
            },

            costoLinea(formula) {
                let costo = this.costoPorMedida(formula);
                if (costo === null) return 0;
                return (parseFloat(formula.quantity_used) || 0) * parseFloat(costo);
            },

            totalCosto() {
                if (!this.selectedService) return 0;
                return this.selectedService.formulas.reduce(
                    (sum, f) => sum + this.costoLinea(f), 0);
            },

            totalMargen() {
                let precio = parseFloat(this.selectedService ? this.selectedService.price : 0);
                return precio - this.totalCosto();
            },

            totalMargenPct() {
                let costo = this.totalCosto();
                if (costo <= 0) return null;
                return ((this.totalMargen() / costo) * 100).toFixed(1);
            },

            async agregarIngrediente() {
                if (!this.nuevo.item_id || !this.nuevo.quantity_used) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Selecciona el producto y la cantidad.' }));
                    return;
                }

                try {
                    let response = await fetch('/formulas', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify(this.nuevo)
                    });

                    let data = await response.json();

                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Ingrediente agregado a la receta!' }));
                        setTimeout(() => { window.location.reload(); }, 900);
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : (data.error || 'Error al agregar.');
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión.' }));
                }
            },

            async guardarCantidad(formula) {
                if (!formula.quantity_used || parseFloat(formula.quantity_used) <= 0) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'La cantidad debe ser mayor a cero.' }));
                    return;
                }

                try {
                    let response = await fetch('/formulas/' + formula.id, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({ quantity_used: formula.quantity_used })
                    });

                    let data = await response.json();

                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: 'Consumo estándar actualizado.' }));
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : (data.error || 'Error al actualizar.');
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión.' }));
                }
            },

            quitarIngrediente(formula) {
                window.dispatchEvent(new CustomEvent('open-confirm', {
                    detail: {
                        message: '¿Quitar este ingrediente de la receta estándar?',
                        action: async () => {
                            let response = await fetch('/formulas/' + formula.id, {
                                method: 'DELETE',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                            });
                            if (response.ok) {
                                this.selectedService.formulas = this.selectedService.formulas.filter(function (f) {
                                    return f.id !== formula.id;
                                });
                                window.dispatchEvent(new CustomEvent('notify', { detail: 'Ingrediente removido de la receta.' }));
                            }
                        }
                    }
                }));
            }
        }
    }
</script>

@php
    // Datos para Alpine: servicios con fórmulas e insumos (incluye precio_c,
    // total_volume y unit_measure del artículo para el cálculo en vivo).
    $serviciosData = $servicios->map(function ($servicio) {
        return [
            'id' => $servicio->id,
            'name' => $servicio->name,
            'price' => (float) $servicio->price,
            'formulas' => $servicio->formulas->map(function ($formula) {
                return [
                    'id' => $formula->id,
                    'item_id' => $formula->item_id,
                    'quantity_used' => (float) $formula->quantity_used,
                    'articulo' => $formula->articulo ? [
                        'id' => $formula->articulo->id,
                        'producto' => $formula->articulo->producto,
                        'marca' => $formula->articulo->marca,
                        'unit_measure' => $formula->articulo->unit_measure ?: 'ml',
                    ] : null,
                ];
            })->filter(function ($formula) {
                return $formula['articulo'] !== null;
            })->values(),
        ];
    })->values();
@endphp

<script>
    const serviciosData = @json($serviciosData);
</script>
@endsection
