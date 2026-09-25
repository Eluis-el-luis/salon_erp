@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6" x-data="catalogoCuentas()"
     @cuenta-seleccionada.window="abrirEditar($event.detail)"
     @eliminar-cuenta.window="eliminarCuenta($event.detail.id)">

    <!-- Header y Acciones -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="page-title">Catálogo de Cuentas</h2>
            <p class="page-subtitle">Visualización jerárquica del catálogo de cuentas con protección de cuentas del sistema</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('catalogo.exportar') }}" class="btn btn-secondary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Exportar Excel
            </a>
            <button @click="mostrarImportar = true" class="btn btn-secondary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Importar Excel
            </button>
            <button @click="abrirCrear()" class="btn btn-primary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Nueva Cuenta
            </button>
        </div>
    </div>

    <!-- Árbol de Cuentas -->
    <div class="card overflow-hidden">
        <div class="card-header bg-gray-50 border-b border-gray-200 px-6 py-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-lg font-bold text-gray-900">Catálogo de Cuentas (Vista Árbol)</h3>
            <div class="flex items-center gap-3 text-sm">
                <label class="flex items-center gap-2 text-gray-600">
                    <input type="checkbox" x-model="mostrarInactivas" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="text-gray-600">Mostrar inactivas</span>
                </label>
                <label class="flex items-center gap-2 text-gray-600">
                    <input type="checkbox" x-model="mostrarProtegidas" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="text-gray-600">Mostrar cuentas del sistema</span>
                </label>
            </div>
        </div>

        <div class="p-4">
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <ul class="divide-y divide-gray-100" role="tree" aria-label="Catálogo de cuentas">
                        @forelse($cuentasArbol as $cuenta)
                            <x-cuenta-row :cuenta="$cuenta" :nivel="0" />
                        @empty
                            <li class="p-8 text-center text-gray-500">No hay cuentas registradas.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Crear/Editar Cuenta -->
    <div x-show="mostrarCrear" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" @click.outside="mostrarCrear = false" style="display: none;">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto" @keydown.escape.window="mostrarCrear = false">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-lg font-bold text-gray-900" x-text="editandoId ? 'Editar Cuenta Contable' : 'Nueva Cuenta Contable'"></h3>
                <button type="button" @click="mostrarCrear = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>

            <form @submit.prevent="guardarCuenta()" class="p-6 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Código *</label>
                        <input type="text" x-model="form.codigo" class="input" placeholder="Ej: 1.1.1.1" required maxlength="20" :readonly="esSistema">
                    </div>
                    <div>
                        <label class="label">Nivel</label>
                        <input type="number" x-model="form.nivel" class="input bg-gray-50 cursor-not-allowed" readonly>
                    </div>
                </div>

                <div>
                    <label class="label">Nombre *</label>
                    <input type="text" x-model="form.nombre" class="input" placeholder="Nombre de la cuenta" required maxlength="100">
                </div>

                <div class="grid grid-cols-2 gap-4" x-show="!esSistema">
                    <div>
                        <label class="label">Tipo *</label>
                        <select x-model="form.tipo" class="input" :required="!esSistema">
                            <option value="">Seleccionar...</option>
                            <option value="activo">Activo</option>
                            <option value="pasivo">Pasivo</option>
                            <option value="patrimonio">Patrimonio</option>
                            <option value="ingreso">Ingreso</option>
                            <option value="gasto">Gasto</option>
                            <option value="costo">Costo</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Naturaleza *</label>
                        <select x-model="form.naturaleza" class="input" :required="!esSistema">
                            <option value="">Seleccionar...</option>
                            <option value="deudora">Deudora</option>
                            <option value="acreedora">Acreedora</option>
                        </select>
                    </div>
                </div>

                <div x-show="!esSistema">
                    <label class="label">Cuenta Padre (opcional)</label>
                    <select x-model="form.cuenta_padre_id" class="input" @change="recalcularNivel()">
                        <option value="">Sin cuenta padre (Nivel 1)</option>
                        <template x-for="cuenta in cuentasDisponiblesParaPadre" :key="cuenta.id">
                            <option :value="cuenta.id" x-text="cuenta.codigo + ' - ' + cuenta.nombre"></option>
                        </template>
                    </select>
                </div>

                <div class="flex items-center gap-3" x-show="!esSistema">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.permite_movimiento" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm text-gray-700">Permite movimiento directo</span>
                    </label>
                </div>

                <p x-show="esSistema" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
                    Esta es una cuenta del sistema: solo puede modificar su nombre. El código está protegido para no romper la operación del POS y la nómina.
                </p>

                <div class="pt-4 flex justify-end gap-3 border-t border-gray-200">
                    <button type="button" @click="mostrarCrear = false" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="guardando">
                        <span x-text="guardando ? 'Guardando...' : (editandoId ? 'Actualizar Cuenta' : 'Guardar Cuenta')"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Importar -->
    <div x-show="mostrarImportar" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" @click.outside="mostrarImportar = false" style="display: none;">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900">Importar Catálogo desde Excel</h3>
                <button type="button" @click="mostrarImportar = false" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>

            <form action="{{ route('catalogo.importar') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <p class="text-sm text-blue-800 mb-2"><strong>Instrucciones:</strong></p>
                    <ul class="text-xs text-blue-700 space-y-1 pl-4 list-disc">
                        <li>Descarga la plantilla usando el botón "Exportar Excel" en la vista principal</li>
                        <li>Llena las columnas: codigo, nombre, tipo, naturaleza, codigo_padre, permite_movimiento, activa</li>
                        <li>Tipos válidos: activo, pasivo, patrimonio, ingreso, gasto, costo</li>
                        <li>Naturaleza: deudora o acreedora</li>
                    </ul>
                </div>

                <div>
                    <label class="label">Archivo Excel (.xlsx)</label>
                    <input type="file" name="archivo" accept=".xlsx,.xls" required class="input">
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" @click="mostrarImportar = false" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Importar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Notifications -->
    <div class="fixed bottom-6 right-6 z-50 space-y-2">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg" :class="toast.tipo === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'">
                <span class="text-sm font-medium" x-text="toast.mensaje"></span>
            </div>
        </template>
    </div>
</div>

@push('scripts')
<script>
    function catalogoCuentas() {
        return {
            cuentas: @json($cuentas),
            mostrarCrear: false,
            mostrarImportar: false,
            guardando: false,
            mostrarInactivas: false,
            mostrarProtegidas: true,
            editandoId: null,
            esSistema: false,
            toasts: [],
            form: {
                codigo: '', nombre: '', tipo: '', naturaleza: 'deudora',
                cuenta_padre_id: '', nivel: 1, permite_movimiento: true, activa: true,
            },

            get cuentasDisponiblesParaPadre() {
                return this.cuentas.filter(c => c.activa && !c.is_system_account && c.id !== this.editandoId);
            },

            abrirCrear() {
                this.resetForm();
                this.editandoId = null;
                this.esSistema = false;
                this.mostrarCrear = true;
            },

            abrirEditar(cuenta) {
                this.editandoId = cuenta.id;
                this.esSistema = !!cuenta.is_system_account;
                this.form = {
                    codigo: cuenta.codigo,
                    nombre: cuenta.nombre,
                    tipo: cuenta.tipo,
                    naturaleza: cuenta.naturaleza,
                    cuenta_padre_id: cuenta.cuenta_padre_id || '',
                    nivel: cuenta.nivel,
                    permite_movimiento: !!cuenta.permite_movimiento,
                    activa: !!cuenta.activa,
                };
                this.mostrarCrear = true;
            },

            recalcularNivel() {
                const padre = this.cuentas.find(c => c.id == this.form.cuenta_padre_id);
                this.form.nivel = padre ? (padre.nivel + 1) : 1;
            },

            async guardarCuenta() {
                this.guardando = true;
                try {
                    const url = this.editandoId
                        ? '{{ url('/catalogo') }}/' + this.editandoId
                        : '{{ route('catalogo.store') }}';

                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(Object.assign({}, this.form, {
                            _method: this.editandoId ? 'PUT' : 'POST',
                        })),
                    });

                    const data = await response.json();

                    if (response.ok) {
                        this.mostrarToast(this.editandoId ? 'Cuenta actualizada' : 'Cuenta creada', 'success');
                        setTimeout(() => window.location.reload(), 700);
                    } else {
                        const msgs = data.errors ? Object.values(data.errors).flat().join(' ') : (data.error || data.message || 'Error');
                        this.mostrarToast(msgs, 'error');
                    }
                } catch (e) {
                    this.mostrarToast(e.message, 'error');
                } finally {
                    this.guardando = false;
                }
            },

            async eliminarCuenta(id) {
                if (!confirm('¿Eliminar esta cuenta? Esta acción no se puede deshacer.')) return;
                try {
                    const response = await fetch('{{ url('/catalogo') }}/' + id, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });
                    const data = await response.json();
                    if (response.ok) {
                        this.mostrarToast('Cuenta eliminada', 'success');
                        setTimeout(() => window.location.reload(), 700);
                    } else {
                        this.mostrarToast(data.error || 'No se pudo eliminar', 'error');
                    }
                } catch (e) {
                    this.mostrarToast(e.message, 'error');
                }
            },

            resetForm() {
                this.form = {
                    codigo: '', nombre: '', tipo: '', naturaleza: 'deudora',
                    cuenta_padre_id: '', nivel: 1, permite_movimiento: true, activa: true,
                };
            },

            mostrarToast(mensaje, tipo = 'success') {
                const id = Date.now();
                this.toasts.push({ id, mensaje, tipo });
                setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 3000);
            },
        }
    }
</script>
@endpush
@endsection
