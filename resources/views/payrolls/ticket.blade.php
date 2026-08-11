<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colilla de Pago - {{ $payroll->user->name }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            body { margin: 0; padding: 0; font-family: monospace; }
            .no-print { display: none; }
            .print-container { width: 100%; max-width: 300px; margin: 0 auto; box-shadow: none; border: none; }
        }
    </style>
</head>
<body class="bg-gray-200 font-sans text-gray-900 py-8">

    <!-- Botones de Acción -->
    <div class="max-w-sm mx-auto mb-4 flex justify-between no-print">
        <a href="javascript:window.close()" class="text-sm text-gray-600 hover:text-gray-900">← Cerrar</a>
        <button onclick="window.print()" class="bg-emerald-600 text-white px-4 py-1 rounded shadow text-sm font-bold">🖨️ Imprimir Colilla</button>
    </div>

    <!-- Contenedor del Ticket -->
    <div class="print-container bg-white mx-auto max-w-sm p-6 rounded shadow-lg border-t-8 border-gray-800">
        
        <!-- Cabecera -->
        <div class="text-center mb-6">
            <h1 class="text-xl font-black uppercase text-gray-800">Álvaro Rugama</h1>
            <p class="text-xs font-bold tracking-widest text-gray-500 uppercase">Recibo de Nómina</p>
            <p class="text-[10px] text-gray-500 mt-2">Documento interno de pago</p>
        </div>

        <div class="border-b border-dashed border-gray-400 mb-4"></div>

        <!-- Info del Empleado -->
        <div class="text-xs mb-4 space-y-2">
            <div class="flex justify-between">
                <span class="font-bold">Recibo #:</span>
                <span>NOM-{{ str_pad($payroll->id, 5, '0', STR_PAD_LEFT) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="font-bold">Colaborador:</span>
                <span class="font-medium text-right">{{ $payroll->user->name }}</span>
            </div>
            <div class="flex justify-between">
                <span class="font-bold">Cargo:</span>
                <span class="uppercase">{{ $payroll->user->role }}</span>
            </div>
            <div class="flex justify-between">
                <span class="font-bold">Período:</span>
                <span>{{ \Carbon\Carbon::parse($payroll->start_date)->format('d/m/y') }} al {{ \Carbon\Carbon::parse($payroll->end_date)->format('d/m/y') }}</span>
            </div>
            <div class="flex justify-between">
                <span class="font-bold">Fecha de Emisión:</span>
                <span>{{ $payroll->created_at->format('d/m/Y') }}</span>
            </div>
        </div>

        <div class="border-b border-dashed border-gray-400 mb-4"></div>

        <!-- Detalle Salarial (Ingresos) -->
        <div class="text-xs space-y-2 mb-4">
            <p class="font-bold uppercase tracking-wider text-gray-500 mb-1">Ingresos</p>
            
            <div class="flex justify-between">
                <span>Salario Base:</span>
                <span>C$ {{ number_format($payroll->active_salary, 2) }}</span>
            </div>
            
            @if($payroll->services_commission > 0)
            <div class="flex justify-between">
                <span>Comisiones Acumuladas:</span>
                <span>C$ {{ number_format($payroll->services_commission, 2) }}</span>
            </div>
            @endif
        </div>

        <!-- Detalle Deducciones -->
        <div class="text-xs space-y-2 mb-4">
            <p class="font-bold uppercase tracking-wider text-gray-500 mb-1">Deducciones</p>
            
            <div class="flex justify-between text-red-600">
                <span>Adelantos de Salario:</span>
                <span>- C$ {{ number_format($payroll->salary_advances, 2) }}</span>
            </div>
        </div>

        <!-- Total Neto -->
        <div class="border-t-2 border-gray-800 pt-2 mb-8 mt-4">
            <div class="flex justify-between font-black text-lg">
                <span>NETO A RECIBIR:</span>
                <span>C$ {{ number_format($payroll->total_to_pay, 2) }}</span>
            </div>
        </div>

        <!-- Firmas -->
        <div class="mt-12 text-center">
            <div class="border-b border-gray-800 w-3/4 mx-auto mb-1"></div>
            <p class="text-[10px] uppercase font-bold text-gray-600">Firma de Recibido Conforme</p>
            <p class="text-[9px] text-gray-500 mt-2">Hago constar que recibo a mi entera satisfacción la cantidad detallada en este documento, sin tener reclamo alguno pendiente.</p>
        </div>

    </div>
</body>
</html>