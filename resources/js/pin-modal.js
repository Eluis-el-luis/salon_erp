/**
 * DOBLE CONFIRMACIÓN POR PIN (Supervisor Override).
 *
 * Componente Alpine global del modal "PIN de Autorización de Supervisor".
 * Se abre despachando el evento de ventana 'open-pin' con un detalle:
 *   { mensaje?: string, form?: HTMLFormElement, callback?: (pin) => void }
 *
 * - Con `form`: inserta un input oculto `pin_supervisor` en el formulario y
 *   lo envía (flujos POST tradicionales: arqueo, caja chica, anulaciones).
 * - Con `callback`: entrega el PIN al flujo que abrió el modal (fetch/JSON).
 *
 * El backend valida el PIN vía el trait App\Traits\AutorizaPinSupervisor.
 */
export default () => ({
    abierto: false,
    pin: '',
    mensaje: '',
    error: '',
    accion: null,

    abrir(detalle) {
        this.accion = detalle || {};
        this.mensaje = this.accion.mensaje
            || 'Esta acción requiere autorización con PIN de supervisor.';
        this.pin = '';
        this.error = '';
        this.abierto = true;
        this.$nextTick(() => this.$refs.pin && this.$refs.pin.focus());
    },

    cerrar() {
        this.abierto = false;
        this.pin = '';
        this.error = '';
        this.accion = null;
    },

    confirmar() {
        this.error = '';

        if (!/^\d{4,6}$/.test(this.pin || '')) {
            this.error = 'El PIN debe tener entre 4 y 6 dígitos.';
            return;
        }

        if (this.accion && this.accion.form) {
            const form = this.accion.form;
            let input = form.querySelector('input[name="pin_supervisor"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'pin_supervisor';
                form.appendChild(input);
            }
            input.value = this.pin;
            this.abierto = false;
            form.submit();
            return;
        }

        if (this.accion && typeof this.accion.callback === 'function') {
            const callback = this.accion.callback;
            this.abierto = false;
            callback(this.pin);
            return;
        }

        this.cerrar();
    }
});
