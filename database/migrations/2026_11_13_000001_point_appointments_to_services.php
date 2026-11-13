<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // MySQL DDL implica commits: no simular atomicidad mediante una transacción PHP.
    public $withinTransaction = false;

    public function up(): void
    {
        $this->changeReference('items', 'appointments_service_id_foreign', 'services', 'appointments_service_id_services_foreign');
    }

    public function down(): void
    {
        $this->changeReference('services', 'appointments_service_id_services_foreign', 'items', 'appointments_service_id_foreign');
    }

    private function changeReference(string $source, string $oldName, string $target, string $newName): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') {
            throw new RuntimeException('H13 requiere MySQL sin prefijo de tablas.');
        }
        $schema = $connection->getDatabaseName();
        $tables = $connection->select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('appointments', 'items', 'services')", [$schema]);
        if (count($tables) !== 3 || collect($tables)->contains(fn ($table) => $table->ENGINE !== 'InnoDB')) {
            throw new RuntimeException('H13: estructura InnoDB incompleta o inesperada.');
        }
        foreach (['appointments' => 'service_id', 'items' => 'id', 'services' => 'id'] as $table => $column) {
            $definition = $connection->selectOne('SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$schema, $table, $column]);
            if (! $definition || $definition->COLUMN_TYPE !== 'bigint unsigned' || $definition->IS_NULLABLE !== 'NO') {
                throw new RuntimeException('H13: tipos de columnas inesperados.');
            }
        }
        $foreign = $connection->select("SELECT k.CONSTRAINT_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=? AND k.TABLE_NAME='appointments' AND k.COLUMN_NAME='service_id' AND k.REFERENCED_TABLE_NAME IS NOT NULL", [$schema]);
        if (count($foreign) !== 1 || $foreign[0]->CONSTRAINT_NAME !== $oldName
            || $foreign[0]->REFERENCED_TABLE_SCHEMA !== $schema || $foreign[0]->REFERENCED_TABLE_NAME !== $source
            || $foreign[0]->REFERENCED_COLUMN_NAME !== 'id'
            || ! in_array($foreign[0]->DELETE_RULE, ['NO ACTION', 'RESTRICT'], true)
            || ! in_array($foreign[0]->UPDATE_RULE, ['NO ACTION', 'RESTRICT'], true)) {
            throw new RuntimeException('H13: clave foránea inesperada; no se modificó.');
        }
        $parts = $connection->selectOne('SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=?', [$schema, 'appointments', $oldName]);
        if ((int) $parts->total !== 1) {
            throw new RuntimeException('H13: no se admite una clave foránea compuesta.');
        }
        // La existencia del mismo número en ambos catálogos NO prueba identidad.
        // Política deliberadamente conservadora en ambos sentidos, incluso sin huérfanos.
        if ($connection->table('appointments')->exists()) {
            throw new RuntimeException('H13: hay citas; se requiere conciliación histórica autorizada antes de cambiar la FK.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('H13: no ejecutar DDL dentro de una transacción activa.');
        }
        // Nombres constantes internos, nunca proporcionados por una solicitud.
        // Requiere exclusión de escritores durante toda la ventana de despliegue.
        $connection->statement("ALTER TABLE `appointments` DROP FOREIGN KEY `$oldName`, ADD CONSTRAINT `$newName` FOREIGN KEY (`service_id`) REFERENCES `$target` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION");
    }
};
