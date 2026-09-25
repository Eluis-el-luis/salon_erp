@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="page-title">Nuevo Periodo Contable</h1>
            <p class="page-subtitle">Defina el rango fiscal sobre el que se registrarán los asientos</p>
        </div>
        <a href="{{ route('periodos.index') }}" class="btn btn-secondary">← Volver a la lista</a>
    </div>

    @if($errors->any())
        <div class="p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <ul class="list-disc pl-5 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('periodos.store') }}" class="card p-6 space-y-5">
        @csrf

        <div>
            <label class="label">Nombre del Periodo *</label>
            <input type="text" name="nombre" value="{{ old('nombre') }}" required maxlength="100"
                   placeholder="Ej. Septiembre 2026" class="input">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="label">Fecha de Inicio *</label>
                <input type="date" name="fecha_inicio" value="{{ old('fecha_inicio') }}" required class="input">
            </div>
            <div>
                <label class="label">Fecha de Fin *</label>
                <input type="date" name="fecha_fin" value="{{ old('fecha_fin') }}" required class="input">
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
            <a href="{{ route('periodos.index') }}" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Crear Periodo</button>
        </div>
    </form>
</div>
@endsection
