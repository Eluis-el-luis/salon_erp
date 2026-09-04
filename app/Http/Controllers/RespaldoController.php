<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;
use Ifsnop\Mysqldump as IMysqldump;

class RespaldoController extends Controller
{
    // 1. Mostrar la Interfaz de Backups
    public function index()
    {
        return view('backups.index');
    }

    // 2. Descargar el archivo .sql
    public function download()
    {
        $fecha = Carbon::now()->format('Y-m-d_H-i-s');
        $nombreArchivo = "Respaldo_SalonERP_{$fecha}.sql";
        $rutaAlmacenamiento = storage_path('app/backups');

        if (!File::exists($rutaAlmacenamiento)) {
            File::makeDirectory($rutaAlmacenamiento, 0755, true);
        }

        $rutaArchivo = $rutaAlmacenamiento . '/' . $nombreArchivo;

        $usuarioDb = env('DB_USERNAME');
        $claveDb = env('DB_PASSWORD', ''); 
        $hostDb = env('DB_HOST', '127.0.0.1');
        $nombreDb = env('DB_DATABASE');

        try {
            // Configuramos la librería para que agregue "DROP TABLE IF EXISTS"
            $configDump = [
                'add-drop-table' => true,
            ];

            // Instanciamos la librería con los nuevos ajustes
            $dump = new IMysqldump\Mysqldump("mysql:host={$hostDb};dbname={$nombreDb}", $usuarioDb, $claveDb, $configDump);
            
            // Generamos el archivo .sql
            $dump->start($rutaArchivo);

            // Forzamos la descarga y luego borramos el archivo del servidor
            return Response::download($rutaArchivo)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al generar el respaldo: ' . $e->getMessage());
        }
    }

    // 3. Restaurar la base de datos desde un archivo .sql (Versión Nativa de Laravel)
    public function restore(Request $request)
    {
        $request->validate([
            'backup_file' => 'required|file|max:51200', // Máximo 50MB
        ]);

        $archivo = $request->file('backup_file');
        
        // Validar extensión
        if ($archivo->getClientOriginalExtension() !== 'sql') {
            return redirect()->back()->with('error', 'El archivo debe ser un formato .sql válido.');
        }

        try {
            // 1. Extraemos todo el texto y consultas del archivo .sql
            $sql = file_get_contents($archivo->getRealPath());

            // 2. Usamos el motor nativo de Laravel para ejecutar el script completo 
            // Esto evita usar comandos de consola (exec) que fallan en Windows
            \Illuminate\Support\Facades\DB::unprepared($sql);

            return redirect()->back()->with('success', '¡Base de datos restaurada con éxito!');

        } catch (\Exception $e) {
            // Si el archivo está corrupto o hay un error de sintaxis SQL, lo capturamos
            return redirect()->back()->with('error', 'Error crítico al restaurar: ' . $e->getMessage());
        }
    }
}