{{-- MODAL GLOBAL: PIN DE AUTORIZACIÓN DE SUPERVISOR (doble confirmación) --}}
<div x-data="pinAutorizacion" @open-pin.window="abrir($event.detail)" x-cloak
     x-show="abierto" style="display: none;" class="fixed inset-0 z-[110] overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 text-center">
        <div x-show="abierto" @click="cerrar()" class="fixed inset-0 bg-gray-900/70 backdrop-blur-sm"></div>
        <div x-show="abierto" @keydown.escape.window="cerrar()"
             x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100" x-transition:leave="ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm z-10 overflow-hidden">
            <div class="bg-gray-900 text-white px-6 py-4">
                <div class="flex items-center gap-3">
                    <svg class="w-6 h-6 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <div>
                        <h3 class="text-base font-bold leading-tight">Autorización de Supervisor</h3>
                        <p class="text-gray-400 text-[11px]">Doble confirmación de seguridad</p>
                    </div>
                </div>
            </div>
            <form @submit.prevent="confirmar()">
                <div class="px-6 py-5 space-y-4">
                    <p class="text-sm text-gray-600" x-text="mensaje"></p>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1 uppercase tracking-wide">PIN de autorización</label>
                        <input x-ref="pin" x-model="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="6"
                               placeholder="••••"
                               class="w-full text-center text-2xl font-black tracking-[0.5em] shadow-sm border border-gray-300 rounded-xl py-3 px-3 focus:ring-2 focus:ring-gray-900 focus:border-gray-900">
                        <p class="text-[11px] text-gray-400 mt-1">Tu PIN de seguridad o el PIN de un supervisor (Álvaro / Administrador).</p>
                        <p class="text-xs text-red-600 font-semibold mt-2" x-show="error" x-text="error" x-cloak></p>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-row-reverse gap-3">
                    <button type="submit" class="btn bg-gray-900 hover:bg-black text-white font-bold py-2.5 px-4 rounded-xl flex-1">
                        Autorizar
                    </button>
                    <button type="button" @click="cerrar()" class="btn btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>
