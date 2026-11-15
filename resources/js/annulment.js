// Comprobación de interfaz; el servidor repite el contrato bajo bloqueo.
export function annulmentError(data) {
    if (!['sin_restitucion', 'devolucion_completa', 'correccion_completa'].includes(data.inventory_intent)) return 'Selecciona expresamente la modalidad de inventario.';
    if (data.inventory_intent !== 'sin_restitucion' && (!data.verification_reference?.trim() || !data.verification_description?.trim() || !data.verified_on || !data.verification_confirmed)) return 'Completa la constatación física y confirma la verificación.';
    if (data.requires_execution) {
        if (!['prestada', 'no_prestada', 'no_determinada'].includes(data.execution_declaration)) return 'Declara expresamente si la prestación fue constatada.';
        if (data.execution_declaration === 'no_determinada') return 'La ejecución no determinada requiere conciliación antes de anular.';
        if (!data.execution_reference?.trim() || !data.execution_description?.trim() || !data.execution_verified_on || !data.execution_confirmed) return 'Completa la constatación retrospectiva de la prestación.';
        // Solo el servidor conoce las salidas: no prestación sin retorno puede ser coherente si no hubo consumo.
    }
    return null;
}

export function authorizeAnnulment(form) {
    if (!form.reportValidity()) return;
    if (form.elements.inventory_intent) {
        const data = Object.fromEntries(new FormData(form));
        data.requires_execution = form.dataset.requiresExecution === 'true';
        const error = annulmentError(data);
        const target = form.querySelector('[data-annulment-error]');
        target.textContent = error || '';
        if (error) return;
    }
    window.dispatchEvent(new CustomEvent('open-pin', { detail: { mensaje: 'Anular exige autorización de un supervisor. La constatación y los efectos se comprobarán en el servidor.', form } }));
}
