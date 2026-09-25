@props(['cuenta', 'nivel' => 0])

<li class="group"
    style="padding-left: {{ $nivel * 16 }}px"
    x-data="{ expandido: {{ $nivel === 0 ? 'true' : 'false' }} }"
    x-show="(mostrarInactivas || {{ $cuenta->activa ? 'true' : 'false' }}) && (mostrarProtegidas || !{{ $cuenta->is_system_account ? 'true' : 'false' }})">
    <div class="flex items-center gap-2 py-2 px-2 hover:bg-gray-50 transition-colors {{ $cuenta->is_system_account ? 'bg-gray-50' : '' }}">
        @if($cuenta->subcuentas->isNotEmpty())
            <button type="button" @click="expandido = !expandido" class="p-1 rounded hover:bg-gray-200 transition-colors text-gray-400 hover:text-gray-600" :aria-expanded="expandido" aria-label="Expandir/Colapsar">
                <svg class="w-4 h-4" x-show="!expandido" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                <svg class="w-4 h-4" x-show="expandido" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            </button>
        @else
            <span class="w-6"></span>
        @endif

        @if($cuenta->is_system_account)
            <span class="ml-1 px-1.5 py-0.5 text-[10px] font-bold rounded bg-amber-100 text-amber-800" title="Cuenta del sistema - Protegida">SYS</span>
        @endif

        @unless($cuenta->activa)
            <span class="ml-1 px-1.5 py-0.5 text-[10px] font-medium rounded bg-gray-100 text-gray-500">INACTIVA</span>
        @endunless

        <div class="flex-1 min-w-0 ml-2">
            <div class="flex items-center gap-2 truncate">
                <span class="font-mono text-sm font-bold text-gray-700">{{ $cuenta->codigo }}</span>
                <span class="font-medium text-gray-900 truncate">{{ $cuenta->nombre }}</span>
            </div>
            <div class="flex items-center gap-1 text-xs text-gray-500">
                <span class="px-1.5 py-0.5 rounded text-xs font-medium {{ $cuenta->naturaleza === 'deudora' ? 'bg-blue-100 text-blue-700' : 'bg-red-100 text-red-700' }}">{{ strtoupper($cuenta->naturaleza) }}</span>
                <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">{{ $cuenta->tipo }}</span>
            </div>
        </div>

        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
            <button type="button" @click="$dispatch('cuenta-seleccionada', @js($cuenta))" class="p-1.5 text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg" title="Editar cuenta" aria-label="Editar cuenta">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21l-7 2 2-7 8.536-8.536z"></path></svg>
            </button>
            @unless($cuenta->is_system_account)
                <button type="button" @click="$dispatch('eliminar-cuenta', @js($cuenta))" class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Eliminar cuenta" aria-label="Eliminar cuenta">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                </button>
            @endunless
        </div>
    </div>

    @if($cuenta->subcuentas->isNotEmpty())
        <ul class="ml-4 border-l border-gray-200 pl-2" role="group" x-show="expandido">
            @foreach($cuenta->subcuentas->sortBy('codigo') as $hijo)
                <x-cuenta-row :cuenta="$hijo" :nivel="$nivel + 1" />
            @endforeach
        </ul>
    @endif
</li>
