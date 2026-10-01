<?php

namespace App\Exceptions;

use Exception;

/**
 * Excepción de reglas de negocio contables cuyo mensaje es seguro para
 * mostrarse al usuario (ej. periodo cerrado, cuenta faltante, descuadre).
 */
class ContabilidadException extends Exception
{
}
