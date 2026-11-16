@extends('layouts.app')
@section('content')
<div class="p-6 space-y-4">
    <h1 class="text-xl font-bold">Políticas y habilitaciones — ensayo documental</h1>
    <p role="status">Datos sintéticos. Ningún acto de esta pantalla constituye aprobación económica real ni permite contabilizar.</p>
    @if(session('success'))<p>{{ session('success') }}</p>@endif
    @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if(!$review)
        @foreach($versions as $v)
            <form method="get" action="{{ route('accounting.administration.review', $v->id) }}">
                <p>Versión {{ $v->version_number }} · {{ $v->status }} · {{ $v->content_hash }}</p>
                <label>Banco a revisar <select name="bank" required><option value="">Seleccione</option>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->alias }} (#{{ $bank->id }})</option>@endforeach</select></label>
                <button type="submit">Revisar procedencia y asociaciones</button>
            </form>
        @endforeach
    @else
        <p>Versión {{ $review['policy']->version_number }} · {{ $review['policy']->status }}</p>
        <p>Hash: {{ $review['policy']->content_hash }}</p>
        <p>Banco #{{ $review['bank']->id }}: {{ $review['bank']->alias }} · NIO</p>
        <p>Vigencia UTC: {{ $review['policy']->valid_from }} — {{ $review['policy']->valid_until }}</p>
        @foreach($review['content']['bindings'] as $binding)
            <p>{{ $binding['role'] }} · {{ $binding['scope_type'] }} {{ $binding['resource_id'] }} → #{{ $binding['account_id'] }} {{ $binding['account']['code'] }} {{ $binding['account']['name'] }}</p>
        @endforeach
        <form method="post" action="{{ route('accounting.administration.record', ['target'=>$review['policy']->id,'action'=>'approve']) }}">
            @csrf
            <input type="hidden" name="bank" value="{{ $review['bank']->id }}"><input type="hidden" name="hash" value="{{ $review['policy']->content_hash }}">
            <label>Desde (ISO con zona) <input name="from" placeholder="2042-01-01T00:00:00Z" required></label>
            <label>Hasta (ISO con zona) <input name="until" placeholder="2042-02-01T00:00:00Z" required></label>
            <label>Referencia documental <input name="reference" maxlength="160" required></label>
            <label>Motivo <textarea name="reason" maxlength="2000" required></textarea></label>
            <button type="submit">Registrar aprobación documental sintética</button>
        </form>
        @foreach($review['history'] as $act)
            @php
                $withdrawn = $review['history']->contains(fn($row) => (int)$row->parent_id === (int)$act->id && $row->action === 'revocation');
                $parentWithdrawn = $act->action === 'enablement' && $review['history']->contains(fn($row) => (int)$row->parent_id === (int)$act->parent_id && $row->action === 'revocation');
                $withdrawn = $withdrawn || $parentWithdrawn;
                $alreadyEnabled = $review['history']->contains(fn($row) => (int)$row->parent_id === (int)$act->id && $row->action === 'enablement');
            @endphp
            <article>
                <p>#{{ $act->id }} {{ $act->action }} · {{ $withdrawn ? 'retirado' : 'registrado' }} · actor #{{ $act->actor_id }} · {{ $act->recorded_at }} UTC</p>
                <p>{{ $act->document_reference }} · {{ $act->reason }} · {{ $act->valid_from }} — {{ $act->valid_until }} UTC</p>
                @if($act->action === 'approval' && !$withdrawn && !$alreadyEnabled)
                    <form method="post" action="{{ route('accounting.administration.record',['target'=>$act->id,'action'=>'enable']) }}">
                        @csrf
                        <label>Desde (ISO con zona) <input name="from" required></label><label>Hasta (ISO con zona) <input name="until" required></label>
                        <label>Referencia <input name="reference" maxlength="160" required></label><label>Motivo <textarea name="reason" maxlength="2000" required></textarea></label>
                        <button>Registrar habilitación documental</button>
                    </form>
                @endif
                @if(in_array($act->action,['approval','enablement']) && !$withdrawn)
                    <form method="post" action="{{ route('accounting.administration.record',['target'=>$act->id,'action'=>'revoke']) }}">
                        @csrf
                        <label>Referencia <input name="reference" maxlength="160" required></label><label>Motivo <textarea name="reason" maxlength="2000" required></textarea></label>
                        <button>Registrar retirada</button>
                    </form>
                @endif
            </article>
        @endforeach
    @endif
</div>
@endsection
