<?php

namespace Tests\Support {
    final class R01RestoreTripwire
    {
        public static array $calls = [];

        public static function fail(string $operation): never
        {
            self::$calls[] = $operation;
            throw new \LogicException('Acceso prohibido por el ensayo R01-C0: '.$operation);
        }

        public function __construct(private string $service) {}
        public function __call(string $method, array $arguments): never { self::fail($this->service.'.'.$method); }
    }

    final class R01UnreadableUpload extends \Illuminate\Http\UploadedFile
    {
        public function __construct(public readonly string $syntheticFilename)
        {
            // Doble sin inicializar archivo nativo: la puerta no debe consultar ningún getter.
            // No se crea, valida, lee ni sube un archivo real.
        }
        public function getRealPath(): string|false { R01RestoreTripwire::fail('upload.path'); }
        public function getContent(): string { R01RestoreTripwire::fail('upload.content'); }
        public function getSize(): int|false { R01RestoreTripwire::fail('upload.size'); }
        public function getClientOriginalExtension(): string { R01RestoreTripwire::fail('upload.extension'); }
    }

    final class R01UnreadableRequest extends \Illuminate\Http\Request
    {
        public function validate(array $rules, ...$params): never { R01RestoreTripwire::fail('request.validate'); }
        public function file($key = null, $default = null): never { R01RestoreTripwire::fail('request.file'); }
        public function all($keys = null): never { R01RestoreTripwire::fail('request.payload'); }
        public function getContent(bool $asResource = false): mixed { R01RestoreTripwire::fail('request.content'); }
    }
}

namespace App\Http\Controllers {
    // Solo cargados dentro del proceso aislado del test. No se modifica PHP global ni producción.
    function file_get_contents(...$arguments): never { \Tests\Support\R01RestoreTripwire::fail('file_get_contents'); }
}
