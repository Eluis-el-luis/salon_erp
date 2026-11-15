const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/js/annulment.js','utf8').replaceAll('export function','function');
const validate = vm.runInNewContext(source+'; annulmentError;');
const valid = { inventory_intent:'sin_restitucion',requires_execution:true,execution_declaration:'prestada',
    execution_reference:'ACTA',execution_description:'Atención constatada',execution_verified_on:'2026-11-14',execution_confirmed:'1' };
test('no selecciona intención ni prestación por defecto',()=>{
    assert.ok(validate({})); assert.ok(validate({...valid,execution_declaration:''}));
});
test('prestación constatada sin restitución requiere evidencia y confirmación',()=>{
    assert.equal(validate(valid),null);
    for(const key of ['execution_reference','execution_description','execution_verified_on','execution_confirmed']) assert.ok(validate({...valid,[key]:''}));
});
test('no determinada bloquea; el servidor decide no prestación según salidas reales',()=>{
    assert.ok(validate({...valid,execution_declaration:'no_determinada'}));
    assert.equal(validate({...valid,execution_declaration:'no_prestada'}),null);
});
test('la constatación de prestación no sustituye recepción física',()=>{
    assert.ok(validate({...valid,inventory_intent:'devolucion_completa'}));
    assert.equal(validate({...valid,inventory_intent:'correccion_completa',verification_reference:'ACTA',
        verification_description:'Material recibido',verified_on:'2026-11-14',verification_confirmed:'1'}),null);
});
test('documento sin cita no inventa una declaración de ejecución',()=>{
    assert.equal(validate({inventory_intent:'sin_restitucion',requires_execution:false}),null);
});
test('no abre el PIN con contrato inválido y conserva los datos escritos',()=>{
    const events=[]; const target={textContent:''};
    const run=vm.runInNewContext(source+'; authorizeAnnulment;',{
        window:{dispatchEvent:e=>events.push(e)},
        FormData:class {constructor(form){return Object.entries(form.fixture);}},
        CustomEvent:class {constructor(type,options){this.type=type;this.detail=options.detail;}}
    });
    const form={reportValidity:()=>true,elements:{inventory_intent:{}},dataset:{requiresExecution:'true'},
        querySelector:()=>target,fixture:{...valid,execution_reference:''}};
    run(form); assert.equal(events.length,0); assert.ok(target.textContent); assert.equal(form.fixture.execution_reference,'');
    form.fixture.execution_reference='ACTA'; run(form);
    assert.equal(events.length,1); assert.equal(events[0].type,'open-pin'); assert.equal(events[0].detail.form,form);
});
test('respeta validación HTML y mantiene el camino heredado sin contrato V2',()=>{
    const events=[];
    const run=vm.runInNewContext(source+'; authorizeAnnulment;',{
        window:{dispatchEvent:e=>events.push(e)},CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}}
    });
    const form={reportValidity:()=>false,elements:{}}; run(form); assert.equal(events.length,0);
    form.reportValidity=()=>true; run(form); assert.equal(events.length,1);
});
