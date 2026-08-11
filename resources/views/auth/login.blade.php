<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Álvaro Rugama ERP</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="max-w-md w-full mx-4">
        <!-- Tarjeta de Login -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            
            <!-- Cabecera de la Tarjeta -->
            <div class="bg-emerald-600 px-6 py-8 text-center">
                <h1 class="text-3xl font-black tracking-tight text-white uppercase">Álvaro Rugama</h1>
                <p class="text-emerald-100 font-medium tracking-widest uppercase text-sm mt-1">Make Up Studio ERP</p>
            </div>

            <!-- Formulario -->
            <div class="p-8">
                <h2 class="text-xl font-bold text-gray-800 mb-6 text-center">Acceso al Sistema</h2>

                <!-- Alerta de Error -->
                @if ($errors->any())
                    <div class="bg-red-50 text-red-600 p-4 rounded-lg mb-6 text-sm font-bold border border-red-100">
                        ⚠️ {{ $errors->first() }}
                    </div>
                @endif

                <form action="{{ route('login') }}" method="POST" class="space-y-5">
                    @csrf
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Correo Electrónico</label>
                        <input type="email" name="email" value="{{ old('email') }}" required autofocus
                            class="w-full shadow-sm border border-gray-300 rounded-lg py-2.5 px-4 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500 transition">
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Contraseña</label>
                        <input type="password" name="password" required 
                            class="w-full shadow-sm border border-gray-300 rounded-lg py-2.5 px-4 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500 transition">
                    </div>

                    <button type="submit" 
                        class="w-full bg-gray-900 hover:bg-gray-800 text-white font-bold py-3 px-4 rounded-lg shadow-md transition duration-200 mt-4">
                        Ingresar al Sistema
                    </button>
                </form>
            </div>
        </div>
        
        <p class="text-center text-xs text-gray-400 mt-6 font-medium">
            &copy; {{ date('Y') }} Todos los derechos reservados.
        </p>
    </div>

</body>
</html>