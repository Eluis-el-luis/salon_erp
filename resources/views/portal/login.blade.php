<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Colaborador - Álvaro Rugama</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 01-7 7h10a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-black text-gray-800">Portal del Colaborador</h1>
                <p class="text-gray-500 text-sm mt-1">Álvaro Rugama Make Up Studio</p>
            </div>

            @if ($errors->any())
                <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('portal.login') }}" class="space-y-5">
                @csrf
                <div>
                    <label class="label" for="email">Correo Electrónico</label>
                    <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus class="input" placeholder="usuario@salon.com">
                </div>

                <div>
                    <label class="label" for="password">Contraseña</label>
                    <input type="password" name="password" id="password" required class="input" placeholder="••••••••">
                </div>

                <button type="submit" class="btn btn-primary w-full py-3">
                    Iniciar Sesión
                </button>
            </form>

            <p class="text-center text-xs text-gray-400 mt-6">
                &copy; {{ date('Y') }} Álvaro Rugama Make Up Studio
            </p>
        </div>
    </div>
</body>
</html>