<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Comparación contable de ensayo</title><link rel="stylesheet" href="/__report-demo/assets/app.css"></head>
<body class="bg-gray-50 text-gray-900"><main class="max-w-5xl mx-auto p-6 space-y-6">
<header class="space-y-2"><p class="font-bold text-amber-800">SIMULACIÓN DE REPORTES — CLASIFICACIÓN HIPOTÉTICA — NO APROBADA PARA USO EMPRESARIAL.</p><h1 class="text-2xl font-bold">Comparación de resultados y balance</h1><p>Solo lectura. No registra cargos, asientos, clasificaciones ni cierres.</p></header>
@if(!auth()->check())
<section class="card p-6"><h2 class="text-xl font-bold">Acceso local de ensayo</h2><form method="post" action="/__report-demo/login" class="space-y-4">@csrf
<label class="block" for="email">Correo de test<input class="input" id="email" name="email" type="email" autocomplete="username" required></label>
<label class="block" for="password">Contraseña<input class="input" id="password" name="password" type="password" autocomplete="current-password" required></label><button class="btn btn-primary">Iniciar sesión de consulta</button></form></section>
@else
<form method="post" action="/__report-demo/logout">@csrf<button class="btn btn-secondary">Cerrar esta sesión</button></form>
@php
    $form=array_replace(['source'=>'offline','case'=>'overview','report'=>'result','month'=>'2030-10','until'=>'2030-10-31','states'=>'all','null_origin'=>'include','period'=>'all'],array_filter(request()->only(['source','case','report','month','until','states','null_origin','period']),'is_string'));
    if($comparison){
        $params=$comparison['parameters'];
        $form=array_replace($form,['source'=>$comparison['snapshot']?'mysql':'offline','report'=>$params['report'],'month'=>substr($params['until'],0,7),'until'=>$params['until'],'states'=>count($params['states'])===1?'posted':'all','null_origin'=>$params['null_origin'],'period'=>isset($params['period_id'])?'fixture1':'all']);
        if(!$comparison['snapshot'])$form['case']=str_contains($comparison['classification_version'],'closing')?'closing':(str_contains($comparison['classification_version'],'incomplete')?'incomplete':'overview');
    }
@endphp
<section class="card p-6 space-y-4"><h2 class="text-xl font-bold">Universo de comparación</h2>
<p>Los ejemplos offline usan IDs ficticios y octubre de2030. MySQL consulta todo el scope declarado, sin asignaciones económicas automáticas: puede mostrar cobertura incompleta.</p>
<form method="post" action="/__report-demo/compare" class="space-y-4">@csrf
<label class="block" for="source">Procedencia<select class="input" name="source" id="source"><option value="offline" @selected($form['source']==='offline')>Offline · fixtures de código</option><option value="mysql" @selected($form['source']==='mysql')>MySQL test · historia sin clasificar</option></select></label>
<label class="block" for="case">Ejemplo offline<select class="input" name="case" id="case"><option value="overview" @selected($form['case']==='overview')>Cuentas alternativas, correctoras y reversas</option><option value="closing" @selected($form['case']==='closing')>Cierre corregido · traslado90</option><option value="incomplete" @selected($form['case']==='incomplete')>Clasificación incompleta</option></select></label>
<label class="block" for="report">Reporte<select class="input" name="report" id="report"><option value="result" @selected($form['report']==='result')>Estado de resultados</option><option value="balance" @selected($form['report']==='balance')>Balance acumulado</option></select></label>
<label class="block" for="month">Mes completo de resultados<input class="input" name="month" id="month" type="month" value="{{ $form['month'] }}" required></label>
<label class="block" for="until">Corte de balance / fin del mes<input class="input" name="until" id="until" type="date" value="{{ $form['until'] }}" required></label>
<label class="block" for="states">Estados<select class="input" name="states" id="states"><option value="all" @selected($form['states']==='all')>Borrador, contabilizado y anulado</option><option value="posted" @selected($form['states']==='posted')>Solo contabilizado</option></select></label>
<label class="block" for="null_origin">Origen NULL<select class="input" name="null_origin" id="null_origin"><option value="include" @selected($form['null_origin']==='include')>Incluir explícitamente</option><option value="exclude" @selected($form['null_origin']==='exclude')>Excluir explícitamente</option></select></label>
<label class="block" for="period">Período (solo resultados offline)<select class="input" name="period" id="period"><option value="all" @selected($form['period']==='all')>Todos por fecha</option><option value="fixture1" @selected($form['period']==='fixture1')>Solo período ficticio1</option></select></label>
<button class="btn btn-primary">Comparar sin guardar</button></form></section>
@if($problem)<div role="alert" id="error-summary" class="border border-red-300 bg-red-50 p-4 rounded-lg">{{ $problem }}</div>@endif
@if($comparison)
@php
    $r=$comparison;
    $s=$r['synthetic'];
    $l=$r['legacy'];
    $labels=['asset'=>'Activo','liability'=>'Pasivo','equity'=>'Patrimonio','income'=>'Ingresos','cost'=>'Costos','operating_expense'=>'Gastos operativos','other_expense'=>'Otros egresos'];
    $money=static fn($n)=>$n===null?'No disponible':(($n<0?'-':'').intdiv(abs($n),100).'.'.str_pad((string)(abs($n)%100),2,'0',STR_PAD_LEFT));
@endphp
<section class="card p-6 space-y-2" id="comparison-result"><h2 class="text-xl font-bold">{{ $r['parameters']['report']==='result'?'Estado de resultados':'Balance general' }} · comparación</h2>
<p class="font-bold">{{ $r['data_origin'] }}</p><p>Lectura no emitida ni archivada. Scope: {{ is_array($r['parameters']['account_scope'])?'subconjunto explícito de cuentas':'todas las cuentas' }}.</p>
<p>Desde: {{ $r['parameters']['from']??'acumulado' }} · Hasta: {{ $r['parameters']['until'] }} · Período: {{ $r['parameters']['period_id']??'todos por fecha' }}.</p>
<p>Estados: {{ implode(', ',$r['parameters']['states']) }} · Origen NULL: {{ $r['parameters']['null_origin'] }}.</p>
<p class="break-all">Versión: {{ $r['classification_version'] }}<br>SHA256: {{ $r['classification_hash'] }}</p>
<p>Snapshot: {{ $r['snapshot']?'MySQL, propietario READ ONLY / REPEATABLE READ':'No aplica: dataset offline, sin lectura de documentos SQL' }}.</p>
@foreach($r['warnings'] as $warning)<p class="text-amber-800" role="status">Advertencia: {{ $warning['kind'] }} @if(isset($warning['account_ids'])) · cuentas {{ implode(', ',$warning['account_ids']) }} @endif</p>@endforeach
@if(!$s)<p class="font-bold text-red-700" role="alert">Cobertura incompleta: clasificación propuesta rechazada. No hay estado sintético completo ni diferencias parciales presentadas como éxito.</p>@endif
</section>
<section class="card p-6 space-y-3"><h2 class="text-xl font-bold">A · Totales heredados reales, reporte global</h2>
@if(!$r['legacy_reported_global'])<p>No disponibles para offline. Los importes siguientes son una representación del fixture, no reportes reales consultados en MySQL.</p>
@else @foreach($r['legacy_reported_global'] as $name=>$value)<p>{{ $name }}: C$ {{ number_format($value,2) }}</p>@endforeach @endif</section>
<section class="card p-6"><h2 class="text-xl font-bold">B / C / D · Universo comparable y diferencias</h2><p>Importes C$; el valor exacto en centavos figura entre paréntesis. B es el contrato legado dentro del scope; C es la hipótesis sintética. No sumar subtotales dos veces.</p>
<div class="overflow-x-auto"><table class="w-full text-left"><caption class="font-bold">Rubro · B legado / C sintético / D diferencia</caption><thead><tr><th scope="col">Rubro</th><th scope="col">B</th><th scope="col">C</th><th scope="col">D</th></tr></thead><tbody>
@foreach($labels as $group=>$label)<tr><th scope="row">{{ $label }}</th><td>{{ $money($l['groups_cents'][$group]??null) }} ({{ $l['groups_cents'][$group]??'—' }})</td><td>{{ $money($s['groups_cents'][$group]??null) }} ({{ $s['groups_cents'][$group]??'—' }})</td><td>{{ $money($r['differences']['groups_cents'][$group]??null) }}</td></tr>@endforeach
</tbody></table></div>
<p>Resultado sintético: {{ $money($s['subtotals_cents']['result']??null) }}. Resultado legado comparable: {{ $money($l['subtotals_cents']['result']??null) }}.</p>
@if($r['parameters']['report']==='balance')<p>Resultado pendiente: {{ $money($s['pending_result_cents']??null) }}. Diferencia de balance: {{ $money($s['balance_difference_cents']??null) }}.</p>@endif</section>
<section class="card p-6 space-y-4"><h2 class="text-xl font-bold">D · Diferencias por cuenta ID</h2>
@foreach($r['differences']['accounts'] as $id=>$a)
@php
    $original=collect($r['source_details'])->firstWhere('account_id',$id);
@endphp
<article class="border rounded-lg p-4"><h3 class="font-bold">#{{ $id }} · {{ $original['current_account']['code']??'' }} · {{ $original['current_account']['name']??'' }}</h3>
<p>Etiqueta actual · {{ ($original['current_account']['active']??true)?'activa':'inactiva con historia' }} · Padre: {{ $original['current_account']['parent_id']??'sin padre' }}.</p>
<p>Grupo legado: {{ $a['legacy']['group']??'sin pertenencia' }} · Sintético: {{ $a['synthetic']['group']??'sin pertenencia' }}.</p>
<p>Aporte legado: {{ $a['legacy']['contribution_cents']??0 }} centavos · Sintético: {{ $a['synthetic']['contribution_cents']??0 }} centavos · Delta: {{ $a['contribution_delta_cents'] }} centavos.</p>
<p>Delta debe: {{ $a['debit_delta_cents'] }} centavos · Delta haber: {{ $a['credit_delta_cents'] }} centavos.</p></article>@endforeach
</section>
<section class="card p-6 space-y-3"><h2 class="text-xl font-bold">D · Detalles originales y motivos</h2><p>Incluye saldos cero y compensados relevantes. Cada detalle aporta una sola vez.</p>
@foreach($r['differences']['details'] as $id=>$d)
@php
    $row=$d['synthetic']??$d['legacy'];
@endphp
<details class="border rounded-lg p-4"><summary class="font-bold">Detalle #{{ $id }} · Cuenta #{{ $row['account_id'] }} · {{ $d['difference'] }} · D {{ $row['debit'] }} / H {{ $row['credit'] }}</summary>
<p>{{ $row['current_account']['code'] }} · {{ $row['current_account']['name'] }}</p><p>Asiento #{{ $row['entry_id'] }} · {{ $row['entry_number']??'' }} · {{ $row['date'] }} · Período #{{ $row['period_id'] }}.</p>
<p>Origen: {{ $row['origin']??'NULL explícito' }} · Estado: {{ $row['state'] }} · Referencia: {{ $row['reference_id']??'sin referencia' }} · Moneda ID: {{ $row['currency_id'] }}.</p>
<p>Grupo legado: {{ $d['legacy_group']??'no incluido' }} · Sintético: {{ $d['synthetic_group']??'no incluido' }}.</p>
<p>Contribución legado: {{ $d['legacy_contribution_cents']??'no incluida' }} centavos · Sintética: {{ $d['synthetic_contribution_cents']??'no incluida' }} centavos.</p>
<p>Motivos: {{ implode(', ',$d['reasons'])?:'mismo detalle y clasificación' }}.</p><p>Delta D/H: {{ $d['debit_delta_cents'] }} / {{ $d['credit_delta_cents'] }} centavos.</p></details>@endforeach</section>
@endif
@endif
</main></body></html>
