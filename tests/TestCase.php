<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: RefreshDatabase ejecuta migrate:fresh, que borra
        // todas las tablas. Jamás debe apuntar a la base real del negocio.
        $base = config('database.connections.mysql.database');
        if ($base === 'salon_erp') {
            throw new \RuntimeException(
                'PELIGRO: la suite de pruebas está apuntando a la base de datos real [salon_erp]. '.
                'Configura DB_DATABASE=salon_erp_test en phpunit.xml o .env.testing.'
            );
        }
    }
}
