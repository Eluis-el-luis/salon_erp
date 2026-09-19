<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión — Álvaro Rugama ERP</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen font-sans">

    <div class="max-w-md w-full mx-4">
        <div class="card overflow-hidden shadow-xl">

            <!-- Cabecera -->
            <div class="bg-emerald-800 px-6 py-10 text-center">
                <span class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-white/10 text-white mb-5">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </span>
                <h1 class="font-display text-3xl font-semibold text-white tracking-tight">Álvaro Rugama</h1>
                <p class="text-emerald-200 font-medium tracking-[0.25em] uppercase text-xs mt-2">Make Up Studio ERP</p>
            </div>

            <!-- Formulario -->
            <div class="p-8">
                <h2 class="text-xl font-bold text-gray-800 mb-6 text-center">Acceso al Sistema</h2>

                @if ($errors->any())
                    <div class="flex items-center gap-3 bg-red-50 text-red-700 p-4 rounded-lg mb-6 text-sm font-semibold border border-red-100">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        {{ $errors->first() }}
                    </div>
                @endif

                <form action="{{ route('login') }}" method="POST" class="space-y-5">
                    @csrf

                    <div>
                        <label class="label" for="email">Correo Electrónico</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                            class="input" placeholder="usuario@salon.com">
                    </div>

                    <div>
                        <label class="label" for="password">Contraseña</label>
                        <input id="password" type="password" name="password" required
                            class="input" placeholder="••••••••">
                    </div>

                    <button type="submit" class="btn btn-primary w-full py-3 text-base mt-2">
                        Ingresar al Sistema
                    </button>
                </form>
            </div>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6 font-medium">
            &copy; {{ date('Y') }} Álvaro Rugama Make Up Studio. Todos los derechos reservados.
        </p>
    </div>

</body>
</html>