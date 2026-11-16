<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Demostración contable — {{ defined('SALON_HTTP_SYNTHETIC_CHARGE') ? 'ensayo HTTP' : 'solo consulta' }}</title><link rel="stylesheet" href="/__pilot-demo/assets/app.css"></head>
<body class="bg-gray-50 text-gray-900"><main class="max-w-5xl mx-auto p-6 space-y-6">
<header class="space-y-2"><p class="text-sm font-bold text-amber-800">SIMULACIÓN FINANCIERA · INFORMACIÓN SINTÉTICA · SIN APROBACIÓN EMPRESARIAL</p><h1 class="text-2xl font-bold">Cargo contable configurable</h1>
@if(defined('SALON_HTTP_SYNTHETIC_CHARGE'))
<p>SIMULACIÓN FINANCIERA — DATOS SINTÉTICOS — NO APROBADO PARA USO EMPRESARIAL. Confirmación sintética habilitada únicamente en este servidor de test.</p>
@else
<p>Demostración de consulta y revisión. Esta interfaz no registra preparados, cargos ni devoluciones.</p>
@endif
@if($screen !== 'login')<a class="text-emerald-700 underline" href="{{ route('pilot.demo.index') }}">Inicio / nuevo borrador sin persistencia</a><form method="post" action="{{ route('pilot.demo.logout') }}">@csrf<button class="btn btn-secondary">Cerrar sesión de demostración</button></form>@endif</header>
@if($errors->any())<div role="alert" tabindex="-1" id="error-summary" class="border border-red-300 bg-red-50 p-4 rounded-lg"><h2 class="font-bold">Revise los datos</h2>@foreach($errors->messages() as $field=>$messages)@foreach($messages as $message)<p><a class="underline" href="#{{ $field }}">{{ $message }}</a></p>@endforeach
@endforeach</div><script>document.getElementById('error-summary').focus();</script>@endif
@if($screen === 'login')
<section class="card p-6"><h2 class="text-xl font-bold">Acceso al entorno de ensayo</h2><p>Utilice únicamente credenciales locales de test. No se usan las credenciales del ERP ordinario.</p><form method="post" action="{{ route('pilot.demo.login') }}" class="space-y-4">@csrf
<label class="block" for="email">Correo de ensayo<input class="input" id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}" aria-describedby="email-error"></label>@error('email')<p id="email-error" class="text-red-700">{{ $message }}</p>@enderror
<label class="block" for="password">Contraseña<input class="input" id="password" name="password" type="password" autocomplete="current-password" required></label><button class="btn btn-primary">Iniciar sesión de consulta</button></form></section>
@elseif($screen === 'index')
<section class="card p-6 space-y-4"><h2 class="text-xl font-bold">Revisar un cargo de ensayo</h2><p>Seleccione una habilitación propia y su banco. La revisión no concede permiso de contabilización.</p>
<form method="post" action="{{ route('pilot.demo.review') }}" class="space-y-4">@csrf
<label class="block" for="authorization_id">Banco NIO / habilitación sintética<select class="input" name="authorization_id" id="authorization_id" required><option value="">Seleccione</option>@foreach($grants as $g)<option value="{{ $g->id }}" @selected((string)old('authorization_id')===(string)$g->id)>{{ $g->alias }} · NIO · habilitación #{{ $g->id }} · {{ $g->valid_from }} — {{ $g->valid_until }} UTC</option>@endforeach</select></label>
@php
    $uuid = old('uuid') ?: (string)\Illuminate\Support\Str::uuid();
@endphp
<label class="block" for="uuid">UUID de operación<input class="input" id="uuid" name="uuid" required value="{{ $uuid }}" aria-describedby="uuid-help uuid-error"></label><p id="uuid-help">Ante respuesta perdida, consulte primero el UUID original. No genere otro para resolver un resultado incierto.</p>
<label class="block" for="classification">Clasificación<select class="input" name="classification" id="classification" required><option value="">Seleccione</option><option value="maintenance" @selected(old('classification')==='maintenance')>Mantenimiento</option><option value="commission" @selected(old('classification')==='commission')>Comisión</option></select></label>
<label class="block" for="amount">Importe NIO exacto<input class="input" id="amount" name="amount" inputmode="decimal" required placeholder="50.00" value="{{ old('amount') }}" aria-describedby="amount-error"></label>
<label class="block" for="recognition_date">Fecha contable de revisión<input class="input" id="recognition_date" name="recognition_date" type="date" required value="{{ old('recognition_date',now()->toDateString()) }}"></label>
<label class="block" for="reference">Referencia documental sintética<input class="input" id="reference" name="reference" maxlength="60" required value="{{ old('reference') }}"></label><label class="block" for="description">Descripción<input class="input" id="description" name="description" maxlength="150" required value="{{ old('description') }}"></label>
@foreach(['uuid','classification','amount','recognition_date','reference','description','authorization_id'] as $field)@error($field)<p id="{{ $field }}-error" class="text-red-700">{{ $message }}</p>@enderror
@endforeach
<button class="btn btn-primary">Revisar sin registrar</button></form></section>
<section class="card p-6 space-y-3"><h2 class="text-xl font-bold">Consultar por UUID</h2><p>Solo documentos propios; el UUID no otorga acceso.</p><form method="get" action="{{ route('pilot.demo.lookup') }}"><label for="lookup_uuid">UUID original</label><input class="input" id="lookup_uuid" name="uuid" required><button class="btn btn-secondary">Consultar resultado</button></form>
@foreach($operations as $o)<article class="border-b py-3"><a class="text-emerald-700 underline" href="{{ route('pilot.demo.document',$o->operation_uuid) }}">{{ $o->operation_uuid }}</a><p>{{ $o->status }} · {{ $o->classification }} · NIO {{ $o->amount }} · {{ $o->recognition_date }}</p></article>@endforeach</section>
@elseif($screen === 'review')
@php
    $r = $review;
@endphp
<section class="card p-6 space-y-3"><h2 class="text-xl font-bold">Revisión sin persistencia</h2><dl><dt>Banco y moneda</dt><dd>{{ $r['bank']->alias }} · NIO</dd><dt>Clasificación</dt><dd>{{ $r['n']['classification'] }}</dd><dt>Importe</dt><dd>NIO {{ $r['n']['amount'] }}</dd><dt>Referencia</dt><dd>{{ $r['n']['reference'] }}</dd><dt>Fecha</dt><dd>{{ $r['n']['recognition_date'] }}</dd><dt>UUID</dt><dd class="break-all">{{ $r['n']['uuid'] }}</dd><dt>Hash documental calculado</dt><dd class="break-all">{{ $r['hash'] }}</dd></dl><p>Versión #{{ $r['policy']->version_number }} · hash {{ $r['policy']->content_hash }}</p>
@foreach($r['bindings'] as $b)<p>{{ $b->semantic_role }} → #{{ $b->account_id }} {{ $b->snapshot['code'] }} {{ $b->snapshot['name'] }}</p>@endforeach
@foreach($r['problems'] as $problem)<p role="status" class="text-amber-800">{{ $problem }}</p>@endforeach
@if($r['existing'])<p>Documento existente: {{ $r['existing']->status }}. No se modificó.</p><a class="underline" href="{{ route('pilot.demo.document',$r['n']['uuid']) }}">Consultar resultado original</a>@endif
@if(defined('SALON_HTTP_SYNTHETIC_CHARGE'))
<form method="post" action="{{ route('pilot.demo.confirm') }}">@csrf
@foreach($r['n'] as $key=>$value)
<input type="hidden" name="{{ $key }}" value="{{ $value }}">
@endforeach
<input type="hidden" name="authorization_id" value="{{ $r['g']->id }}"><p>SIMULACI?N FINANCIERA ? DATOS SINT?TICOS ? NO APROBADO PARA USO EMPRESARIAL.</p>
<button class="btn btn-primary">Confirmar expresamente el cargo sintético</button></form>
@else
<p id="confirm-help">Confirmación financiera no habilitada por HTTP. El ensayo financiero continúa exclusivamente mediante el arnés CLI ya autorizado, con sus preflight y guardias.</p><button disabled aria-describedby="confirm-help" class="btn btn-secondary">Confirmar cargo — bloqueado en esta interfaz</button>
@endif
</section>
@elseif($screen === 'document')
@php
    $d = $document;
    $originalContent = json_decode($d['o']->content, true, 512, JSON_THROW_ON_ERROR);
@endphp
<section class="card p-6 space-y-3"><h2 class="text-xl font-bold">Documento {{ $d['o']->status }}</h2><p class="break-all">UUID {{ $d['o']->operation_uuid }}</p><p>{{ $d['bank']->alias }} · NIO {{ $d['o']->amount }} · {{ $d['o']->classification }} · {{ $d['o']->recognition_date }}</p><p>Versión #{{ $d['policy']->version_number }} · hash original {{ $d['o']->policy_hash }}</p><p>Habilitación sintética #{{ $d['g']->id }} {{ $d['revoked'] ? 'retirada; historia conservada' : 'documentada; no habilita esta interfaz' }}</p>
<p>Referencia: {{ $originalContent['reference'] }} · {{ $originalContent['description'] }}</p><p class="break-all">Hash del contenido original: {{ $d['o']->content_hash }}</p>
@foreach($d['bindings'] as $b)
<p>Cuenta fotografiada: {{ $b->semantic_role }} · #{{ $b->account_id }} · {{ $b->snapshot['code'] }} {{ $b->snapshot['name'] }}</p>
@endforeach
@if($d['o']->status === 'prepared')<p role="status" class="text-amber-800">Prepared no equivale a éxito financiero. No tiene movimiento ni asiento confirmado. Solo consulta, sin continuación por HTTP.</p>@else
<p>Movimiento #{{ $d['movement']->id }} · {{ $d['movement']->tipo }} · NIO {{ $d['movement']->monto }} · conciliación {{ $d['movement']->estado_conciliacion }}</p><p>Extracto asociado: {{ $d['movement']->conciliacion_id ?? 'ninguno' }} · fecha de conciliación {{ $d['movement']->fecha_conciliacion ?? 'ninguna' }}</p>
<p>Saldo actual del banco de ensayo: {{ $d['bank']->currency }} {{ $d['bank']->saldo_actual }}. Puede incluir operaciones posteriores; no sustituye la evidencia del cargo.</p>
<h3 class="font-bold">Asiento original #{{ $d['entry']->id }} · {{ $d['entry']->numero_asiento }}</h3>@foreach($d['details'] as $l)<p>Detalle #{{ $l->id }} · cuenta #{{ $l->cuenta_id }} · moneda #{{ $l->moneda_id }} · DEBE {{ $l->debe }} / HABER {{ $l->haber }}</p>@endforeach
<h3 class="font-bold">Correspondencias originales</h3>@foreach($d['resolutions'] as $r)<p>{{ $r->semantic_role }} · binding #{{ $r->binding_id }} · cuenta #{{ $r->account_id }} · detalle #{{ $r->detail_id }}</p>@endforeach
@if($d['refund'])<h3 class="font-bold">Devolución acreditada sintética — solo consulta</h3><p>Documento #{{ $d['refund']->id }} · UUID {{ $d['refund']->operation_uuid }} · {{ $d['refund']->status }} · NIO {{ $d['refund']->amount }}</p><p>Movimiento inverso #{{ $d['refund']->result_movement_id }} · asiento inverso #{{ $d['refund']->result_asiento_id }} · fecha {{ $d['refund']->effective_date }}</p>@foreach($d['refundLines'] as $r)<p>Detalle original #{{ $r->original_detail_id }} → inverso #{{ $r->reversal_detail_id }} · cuenta #{{ $r->account_id }} · DEBE {{ $r->reversal_debe }} / HABER {{ $r->reversal_haber }}</p>@endforeach<p>El cargo original y su conciliación permanecen visibles. No hay botón de devolución.</p>@else<p>Sin devolución confirmada visible para esta sesión.</p>@endif
@endif</section>
@endif
</main></body></html>
