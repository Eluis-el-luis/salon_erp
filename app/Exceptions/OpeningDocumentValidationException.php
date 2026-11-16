<?php

namespace App\Exceptions;

/** Errores por campo, traducibles a 422 por un futuro request; no requiere arrancar Laravel. */
final class OpeningDocumentValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Contrato documental de apertura rechazado.');
    }
}
