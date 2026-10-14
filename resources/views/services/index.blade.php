@extends('layouts.app')

@section('content')
<div x-data="serviceManager()" class="space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="page-title">Catálogo de Servicios</h2>
            <p class="page-subtitle">Categorías, variantes de precio, impuestos y margen bruto sobre el costo actual de insumos.</p>
        </div>
        <button @click="openCreateModal()" class="btn btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nuevo Servicio
        </button>
    </div>

    <!-- Tabla de Servicios -->
    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Servicio</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Duración</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Precio</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Costo Insumos</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Margen Bruto</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($servicios as $servicio)
                    @php
                        $costo = $servicio->costoInsumos();
                        $margen = $servicio->margenBruto();
                        $margenPct = $servicio->margenPorcentaje();
                        $rango = $servicio->rangoPrecios();
                        $hayVariantes = $rango !== null;
                    @endphp
                    <tr class="hover:bg-gray-50 transition" @if(!$servicio->is_active) style="opacity: .6" @endif>
                        <td class="px-6 py-4">
                            <div class="text-sm font-bold text-gray-900">{{ $servicio->name }}</div>
                            <div class="flex items-center gap-2 mt-0.5">
                                @if ($servicio->categoria)
                                    <span class="px-2 inline-flex text-[10px] leading-4 font-bold rounded-full text-white" style="background: {{ $servicio->categoria->color ?? '#6b7280' }};">
                                        {{ $servicio->categoria->name }}
                                    </span>
                                @endif
                                @if ($servicio->plantillaImpuesto)
                                    <span class="text-[10px] font-bold text-gray-400 uppercase">{{ $servicio->plantillaImpuesto->name }}</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5">{{ $servicio->description ?? 'Sin descripción' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 font-medium">
                            <span class="inline-flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                {{ $servicio->duration }} min
                            </span>
                            @if ($servicio->cleanup_minutes > 0)
                                <div class="text-[11px] text-amber-600 font-semibold">+ {{ $servicio->cleanup_minutes }} min limpieza</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-emerald-600">
                            @if ($hayVariantes)
                                <span class="text-xs text-gray-400 font-medium">Desde</span><br>
                                C$ {{ number_format($rango[0], 2) }}
                                @if ($rango[1] != $rango[0])
                                    <span class="text-gray-400">– C$ {{ number_format($rango[1], 2) }}</span>
                                @endif
                                <div class="text-[10px] text-gray-400 font-semibold uppercase mt-0.5">{{ $servicio->variantes->where('is_active', true)->count() }} variantes</div>
                            @else
                                C$ {{ number_format($servicio->price, 2) }}
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-gray-700">
                            @if ($costo > 0)
                                C$ {{ number_format($costo, 2) }}
                            @else
                                <span class="text-gray-400 italic text-xs">Sin costo</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold">
                            @if ($margenPct !== null)
                                <span class="{{ $margenPct >= 50 ? 'text-emerald-600' : ($margenPct >= 20 ? 'text-amber-600' : 'text-red-600') }}">
                                    C$ {{ number_format($margen, 2) }} <span class="text-xs">({{ number_format($margenPct, 1) }}%)</span>
                                </span>
                            @else
                                <span class="text-gray-400 italic text-xs">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            @if($servicio->is_active)
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Activo</span>
                            @else
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactivo</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            @if($servicio->is_active)
                                <button @click="editService(@json($servicio))" class="text-blue-600 hover:text-blue-900 mr-3 transition">Editar</button>
                                <button @click="deleteService({{ $servicio->id }})" class="text-red-600 hover:text-red-900 transition">Dar de baja</button>
                            @else
                                <span class="text-gray-400 text-xs italic">No disponible</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay servicios registrados. ¡Comienza agregando el primero!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- MODAL PARA CREAR/EDITAR SERVICIO -->
    <div x-show="openModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 py-8 text-center">

            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>

            <div x-show="openModal" class="relative bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:max-w-2xl sm:w-full z-10">
                <div class="bg-white px-6 pt-5 pb-4 sm:p-6">
                    <h3 class="text-lg font-bold text-gray-900 border-b pb-2 mb-4" x-text="editMode ? 'Editar Servicio' : 'Nuevo Servicio'"></h3>

                    <form class="space-y-4" @submit.prevent="saveService()">

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="label">Nombre del Servicio <span class="text-red-500">*</span></label>
                                <input type="text" x-model="form.name" class="input" placeholder="Ej. Alisado de Keratina">
                            </div>
                            <div>
                                <label class="label">Categoría</label>
                                <div class="flex gap-2">
                                    <select x-model="form.service_category_id" class="input">
                                        <option value="">-- Sin categoría --</option>
                                        <template x-for="categoria in categorias" :key="categoria.id">
                                            <option :value="categoria.id" x-text="categoria.name"></option>
                                        </template>
                                    </select>
                                    <button type="button" @click="nuevaCategoriaAbierta = !nuevaCategoriaAbierta" class="text-xs font-bold text-emerald-600 hover:text-emerald-800 whitespace-nowrap">+ Nueva</button>
                                </div>
                                <div x-show="nuevaCategoriaAbierta" style="display: none;" class="flex gap-2 mt-2">
                                    <input type="text" x-model="categoriaNueva.name" class="input" placeholder="Ej. Peinados">
                                    <button type="button" @click="crearCategoria()" class="btn btn-secondary py-1.5 px-3 text-xs">Guardar</button>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="label">Precio Base (C$) <span class="text-red-500">*</span></label>
                                <input type="number" step="0.01" min="0" x-model.number="form.price" class="input" placeholder="0.00">
                            </div>
                            <div>
                                <label class="label">Duración Ejecución <span class="text-red-500">*</span></label>
                                <select x-model.number="form.duration" class="input">
                                    <option value="15">15 min</option>
                                    <option value="30">30 min</option>
                                    <option value="45">45 min</option>
                                    <option value="60">1 hora</option>
                                    <option value="90">1.5 horas</option>
                                    <option value="120">2 horas</option>
                                    <option value="180">3 horas</option>
                                    <option value="240">4+ horas</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Limpieza (buffer)</label>
                                <input type="number" min="0" max="480" x-model.number="form.cleanup_minutes" class="input" placeholder="0">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="label">Plantilla de Impuesto</label>
                                <select x-model="form.tax_template_id" class="input">
                                    <option value="">-- Sin regla fiscal --</option>
                                    <template x-for="plantilla in plantillas" :key="plantilla.id">
                                        <option :value="plantilla.id" x-text="plantilla.name + ' (' + parseFloat(plantilla.rate) + '%)'"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="label">Descripción / Notas</label>
                                <input type="text" x-model="form.description" class="input" placeholder="Ej. Incluye lavado y secado...">
                            </div>
                        </div>

                        <!-- VARIANTES DE PRECIO -->
                        <div class="bg-emerald-50/60 border border-emerald-100 rounded-xl p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="text-xs font-black text-emerald-800 uppercase">Variantes de Precio</span>
                                    <p class="text-[11px] text-emerald-700">Ej. Keratina: Corto C$1,500 · Medio C$2,000 · Largo C$2,500</p>
                                </div>
                                <button type="button" @click="addVariante()" class="text-xs font-bold text-emerald-600 hover:text-emerald-800">+ Agregar variante</button>
                            </div>

                            <div class="space-y-2">
                                <template x-for="(variante, index) in form.variantes" :key="index">
                                    <div class="grid grid-cols-12 gap-2 items-center">
                                        <input type="text" x-model="variante.name" class="input col-span-5" placeholder="Nombre (Corto/Medio/Largo)">
                                        <input type="number" step="0.01" min="0" x-model.number="variante.price" class="input col-span-3" placeholder="Precio">
                                        <input type="number" min="0" x-model.number="variante.duration" class="input col-span-3" placeholder="Duración (opcional)">
                                        <button type="button" @click="form.variantes.splice(index, 1)" class="col-span-1 text-red-500 hover:text-red-700 font-black">&times;</button>
                                    </div>
                                </template>
                                <p x-show="form.variantes.length === 0" class="text-xs text-gray-400 italic">
                                    Sin variantes: el POS usará el precio base.
                                </p>
                            </div>
                        </div>

                    </form>
                </div>

                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                    <button @click="saveService()" type="button" class="btn btn-primary">Guardar</button>
                    <button @click="openModal = false" type="button" class="btn btn-secondary">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function serviceManager() {
        return {
            openModal: false,
            editMode: false,
            editId: null,
            categorias: @json($categorias),
            plantillas: @json($plantillas),
            nuevaCategoriaAbierta: false,
            categoriaNueva: { name: '' },

            formBase() {
                return {
                    name: '', price: '', duration: 30, description: '',
                    service_category_id: '', tax_template_id: '',
                    cleanup_minutes: 0, variantes: []
                };
            },
            form: {
                name: '', price: '', duration: 30, description: '',
                service_category_id: '', tax_template_id: '',
                cleanup_minutes: 0, variantes: []
            },

            openCreateModal() {
                this.editMode = false;
                this.editId = null;
                this.form = this.formBase();
                this.nuevaCategoriaAbierta = false;
                this.openModal = true;
            },

            addVariante() {
                this.form.variantes.push({ id: null, name: '', price: '', duration: '' });
            },

            editService(service) {
                this.editMode = true;
                this.editId = service.id;
                this.form = {
                    name: service.name,
                    price: service.price,
                    duration: service.duration,
                    description: service.description || '',
                    service_category_id: service.service_category_id || '',
                    tax_template_id: service.tax_template_id || '',
                    cleanup_minutes: service.cleanup_minutes || 0,
                    variantes: (service.variantes || []).map(function (v) {
                        return { id: v.id, name: v.name, price: v.price, duration: v.duration || '' };
                    })
                };
                this.nuevaCategoriaAbierta = false;
                this.openModal = true;
            },

            async crearCategoria() {
                if (!this.categoriaNueva.name) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Escribe el nombre de la categoría.' }));
                    return;
                }

                try {
                    let response = await fetch('/servicios/categorias', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify(this.categoriaNueva)
                    });

                    let data = await response.json();

                    if (response.ok) {
                        this.categorias.push(data.categoria);
                        this.form.service_category_id = data.categoria.id;
                        this.categoriaNueva = { name: '' };
                        this.nuevaCategoriaAbierta = false;
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Categoría creada!' }));
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : (data.error || 'Error al crear la categoría.');
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión.' }));
                }
            },

            async saveService() {
                if (this.form.name === '' || this.form.price === '') {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Nombre y Precio son obligatorios.' }));
                    return;
                }

                let url = this.editMode ? `/servicios/${this.editId}` : '/servicios';
                let method = this.editMode ? 'PUT' : 'POST';

                let payload = { ...this.form };
                ['service_category_id', 'tax_template_id'].forEach(function (campo) {
                    if (payload[campo] === '' || payload[campo] === undefined) payload[campo] = null;
                });
                // Variantes incompletas se descartan silenciosamente
                payload.variantes = (payload.variantes || []).filter(function (v) {
                    return v.name && v.price !== '' && v.price !== undefined;
                });

                try {
                    let response = await fetch(url, {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify(payload)
                    });

                    let data = await response.json();

                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: this.editMode ? '¡Servicio actualizado!' : '¡Servicio registrado con éxito!' }));
                        this.openModal = false;
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : (data.error || 'Error al guardar los datos.');
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión con el servidor.' }));
                }
            },

            deleteService(id) {
                window.dispatchEvent(new CustomEvent('open-confirm', {
                    detail: {
                        message: '¿Dar de baja este servicio? Ya no aparecerá en la caja para facturar.',
                        action: async () => {
                            let response = await fetch(`/servicios/${id}`, {
                                method: 'DELETE',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                            });
                            if (response.ok) {
                                window.dispatchEvent(new CustomEvent('notify', { detail: '¡Servicio dado de baja!' }));
                                setTimeout(() => { window.location.reload(); }, 1000);
                            }
                        }
                    }
                }));
            }
        }
    }
</script>
@endsection
