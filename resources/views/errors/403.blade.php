<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado - ERP</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    
    <div class="max-w-md w-full mx-4 bg-white rounded-2xl shadow-xl p-8 text-center border-t-4 border-red-500">
        
        <!-- Ícono de Alerta -->
        <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-6 shadow-inner">
            <svg class="h-10 w-10 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>
        
        <h2 class="text-3xl font-black text-gray-900 mb-2">¡Alto ahí!</h2>
        <p class="text-xs font-bold tracking-widest text-red-500 uppercase mb-4">Error 403: Acceso Denegado</p>
        
        <!-- Aquí se imprime el mensaje personalizado que mandamos desde el Middleware -->
        <p class="text-gray-600 mb-8 font-medium">
            {{ $exception->getMessage() ?: 'No tienes los permisos necesarios para acceder a este módulo del sistema.' }}
        </p>

        <!-- Botones de Acción -->
        <div class="space-y-3">
            <!-- Botón de JavaScript para retroceder en el historial -->
            <button onclick="window.history.back()" class="w-full bg-gray-900 hover:bg-gray-800 text-white font-bold py-3 px-4 rounded-lg shadow-md transition duration-200">
                ← Regresar a la página anterior
            </button>
            
            <!-- Botón de emergencia para ir al inicio -->
            <a href="{{ url('/') }}" class="block w-full text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50 rounded-lg font-bold py-3 text-sm transition">
                Ir al Dashboard (Inicio)
            </a>
        </div>

    </div>

</body>
</html>