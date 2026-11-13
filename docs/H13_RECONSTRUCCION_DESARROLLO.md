# H13 — Preparación de reconstrucción de salon_erp

Nota posterior: las limitaciones de seeders descritas aquí son el diagnóstico
histórico. H13-B corrige el código y verifica repetición transaccional sobre
el esquema existente; ver H13_B_INICIALIZACION.md. La reconstrucción y su ensayo
completo en un destino adicional siguen sin ejecutarse ni estar autorizados.

Estado: respaldo creado; reconstrucción NO ejecutada ni habilitada todavía.
El propietario acepta descartar datos ficticios DESPUÉS del respaldo. Esa
decisión no autoriza ejecutar migrate:fresh en esta fase.

## Inspección

Hay 74 archivos de migración, ordenados por nombre. Desarrollo tiene 73
aplicadas; pruebas tiene 74, incluida H13. Las 74 migraciones fueron revisadas
estáticamente (DDL, operaciones de datos, seeders invocados y posibles salidas).
El recorrido completo de esquema vacío ya pasó en las pruebas H13; NO se ha
ensayado DatabaseSeeder completo en esta fase ni se modificó salon_erp_test.

No se encontraron HTTP, procesos externos, correo, escritura de archivos ni
conexiones SQL alternativas explícitas en migraciones/seeders. H13 resuelve
la conexión predeterminada. Los seeders usan Eloquent/DB de Laravel.

Efectos no limitados a crear tablas:
- 2026_07_31_061430 elimina y recrea sales/sale_details en up(). No sirve como
  actualización conservadora sobre datos; en una reconstrucción ya vacía no
  introduce pérdida adicional. No se cambia esta migración histórica.
- Migraciones de cuentas añaden/desactivan cuentas si ya existe su padre;
  algunas actualizan flags de todas las cuentas existentes.
- Migraciones de roles insertan Contador/Álvaro y transforman el enum de users.
- Ciclo de ventas convierte estados históricos; banco/cambio completa bóvedas
  de destino; HRMS hace backfill de empleados a partir de users existentes.
- Bancos/SCM invocan CuentasComplementariasSeeder; HRMS invoca RrhhBaseSeeder;
  administración invoca AdminCatalogoSeeder. Estas llamadas ocurren antes
  del DatabaseSeeder final y deben considerarse en la inicialización.
- H13 rechaza appointments no vacía y cambia la FK a services solo sobre vacío.
  DatabaseSeeder no crea citas, de modo que el orden normal de esquema nuevo
  satisface esta condición sin modificar ni relajar H13.

## Seeders: reconstrucción disponible y problemas

DatabaseSeeder llama, en este orden:
Role, User, Provider, Item, Service, Formula, ContabilidadBase,
CuentasComplementarias, AdminCatalogo, RrhhBase.

| Componente | Qué crea | Limitación encontrada |
| --- | --- | --- |
| RoleSeeder | Cinco roles | Las migraciones ya crean Contador y Álvaro; create duplica nombres, aunque roles.name no es UNIQUE |
| UserSeeder | Cinco usuarios activos, propietario admin@salon.com con role=alvaro | Contraseñas de ejemplo en el código, no reproducidas aquí; sin PIN de supervisor ni expedientes employees |
| ProviderSeeder | Dos proveedores demo | Usa create; los IDs de una segunda corrida no son intercambiables con los originales |
| ItemSeeder | Dos artículos demo, stock 5 y 3, volumen abierto | Omite type, asigna provider_id=2 y carga stock sin lotes, kárdex o asiento; NO usar así para una inicialización íntegra |
| ServiceSeeder | Corte y alisado, precios/duraciones | No reproduce el tercer servicio ni modificaciones actuales; no enlaza categorías/plantillas explícitamente |
| FormulaSeeder | Fórmula del servicio 2 con artículo 2 | IDs hardcodeados; sustituir por referencias deterministas dentro de los fixtures, no por semejanza de nombres entre catálogos |
| ContabilidadBaseSeeder | NIO/USD, período agosto 2026 y catálogo base | No idempotente; no crea 3.3, inserta 7.2 activa y no marca is_system_account |
| CuentasComplementariasSeeder | Retenciones, propinas, divisas, IVA, mermas y otras cuentas | Depende de que exista el padre; no repone 3.3 ni desactiva 7.2 ni protege el catálogo |
| AdminCatalogoSeeder | Grupos, categorías e impuestos | Idempotente, ya invocado al migrar |
| RrhhBaseSeeder | Ausencias, turnos y estructura salarial | Idempotente, ya invocado al migrar; no crea employees de usuarios sembrados después |

Problemas previos al borrado:
1. El backfill employees corre antes de UserSeeder: no genera expedientes de
   esos usuarios. Proponer inicialización de sus expedientes después de users.
2. La migración de retiros omite 3.3 si no existe el padre 3; después el seeder
   base crea el catálogo y 7.2 activa. La marca is_system_account se aplica
   también antes del catálogo. Proponer un paso final idempotente que reproduzca
   explícitamente esas reglas ya existentes: 3.3 patrimonial, 7.2 inactiva/no
   imputable y protección de cuentas base. No cambiar reglas financieras.
3. No sembrar stock físico sin documentos. Proponer catálogo demo con type
   explícito, existencia_actual=0 y current_volume=0. La carga inicial legítima
   H01-B continúa pendiente; no simularla mediante compras ficticias.
4. Recuperar acceso con propietario activo/role_id correcto y contraseña local
   segura, definida mediante entrada protegida; no argumentos CLI ni logs.
   Preparar PIN hasheado para operaciones que lo exigen. No hay implementación
   nueva de credenciales en esta fase.

Estas correcciones de inicialización requieren revisión/autorización y ensayo
en otra base desechable autorizada. NO usar ni reconstruir salon_erp_test para
este ensayo bajo la autorización actual. No ejecutar DatabaseSeeder actual
sin decidir antes cómo resolver estas limitaciones.

No hay aquí una dependencia técnica externa irrecuperable del esquema H13.
Sí hay datos elegidos manualmente/configuración que las semillas no reproducen.
No puede prometerse una instalación operativa equivalente por solo ejecutar
migrate:fresh --seed.

## Qué se perderá y qué permanece

Se perderán filas de desarrollo: usuarios/hashes/PIN, sesiones/tokens, clientes,
citas, venta 106 y demás ventas/pagos, compras, lotes/kárdex, saldos, asientos,
RRHH/nóminas, bancos, divisas, conciliaciones, metas y personalizaciones de
catálogos, fórmulas, roles y configuraciones guardadas en tablas.

El esquema puede regenerarse. Las semillas reproducen solo sus valores demo
y bases descritos arriba, no el contenido actual. No siembran clientes, citas
ni facturas; bancos/cuentas operativas, tasas de cambio y saldos requieren
configuración posterior. Contabilidad base aporta subcuentas de bancos, no
las cuentas bancarias operativas. El período seed fijo no es el período actual;
el servicio contable crea períodos según la fecha de operación.

.env, APP_KEY, configuración en archivos, código, uploads y public/build no
se borran con el comando previsto. No regenerar APP_KEY ni eliminar archivos.
El SQL no respalda esos archivos, credenciales SQL ni permisos del servidor.

## Respaldo creado en esta fase

Archivo: E:\salon-erp-backups\salon_erp_20261113_054136_1807232_2e37f496.sql
Tamaño: 177714 bytes.
SHA256: FF0753F2EBF11A9EE50EEB878F7AB3D2738CDACF532A05FDE0DBC0C987145EB5
Sidecar: mismo nombre más .sha256. Ningún respaldo anterior sobrescrito.

mysqldump 8.4.3, cuenta efectiva root@localhost por TCP 127.0.0.1:3306;
no se usó tester ni contraseña en argumentos. Opciones: --single-transaction
--quick --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF
--column-statistics=0 --routines --events --triggers --hex-blob
--default-character-set=utf8mb4 --skip-add-drop-table --skip-add-locks.
Solo salon_erp, sin --databases. Se escribió un .partial exclusivo, se comprobó
exit 0/no vacío y se renombró sin sobrescribir. Se releyó y comparó SHA256.
Se verificaron 78 CREATE TABLE, marcador final y ausencia de USE/CREATE/DROP
DATABASE. No se imprimió contenido del dump.

El servidor informa 78 tablas InnoDB, cero rutinas, eventos y triggers de
desarrollo, y ninguna FK a otro esquema. Los flags incluyen esos objetos si
existieran al exportar. El dump incluye datos privados y hashes/tokens:
mantenerlo fuera del repositorio y restringir su acceso local.

Limitaciones: single-transaction cubre datos InnoDB, no DDL concurrente ni
cambios posteriores. SHA256 no demuestra restaurabilidad ni equivalencia
semántica. No se ha restaurado el archivo: ese ensayo requiere otra base y
autorización. Si hay nuevas escrituras antes del borrado, crear/verificar un
nuevo respaldo en la ventana final, sin sobrescribir este.

## Comando de reconstrucción preparado — NO EJECUTADO

Primero: corregir/validar inicialización, disponer de credenciales/PIN locales,
respaldar en la ventana final y detener todos los escritores del proyecto
(HTTP, colas, scheduler y otros clientes). No afectar servicios de otros proyectos.
Revalidar destino, cuenta, permisos, cachés y variables en el mismo proceso.

El comando de esquema subyacente es:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' artisan migrate:fresh --database=mysql --force
```

--database=mysql es un NOMBRE de conexión, no prueba que seleccione salon_erp.
Por eso se propone ejecutar esta variante con guardias en el mismo proceso,
únicamente tras autorización expresa; no requiere cambiar permisos ni .env:

```powershell
Set-Location -LiteralPath 'E:\laragon\www\salon-erp'
@'
<?php
ini_set('zend.exception_ignore_args', '1');
try {
    $root = 'E:/laragon/www/salon-erp';
    if (realpath(getcwd()) !== realpath($root) || is_file($root.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('Raíz o caché no autorizada');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $cfg = $app['config']->get('database.connections.mysql');
    if (!$app->environment('local') || $app['config']->get('database.default') !== 'mysql'
        || $cfg['driver'] !== 'mysql' || $cfg['database'] !== 'salon_erp'
        || $cfg['username'] !== 'root' || $cfg['host'] !== '127.0.0.1'
        || (int) $cfg['port'] !== 3306 || !empty($cfg['url'])
        || !empty($cfg['unix_socket']) || !empty($cfg['read']) || !empty($cfg['write'])
        || !empty($cfg['prefix'])) {
        throw new RuntimeException('Destino no autorizado');
    }
    $app['config']->set('database.connections', ['mysql' => $cfg]);
    $db = $app['db']->connection('mysql');
    $id = $db->selectOne('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, @@port AS server_port');
    if ($id->database_name !== 'salon_erp' || $id->account_name !== 'root@localhost' || (int) $id->server_port !== 3306) {
        throw new RuntimeException('Identidad SQL no autorizada');
    }
    $code = $kernel->call('migrate:fresh', ['--database' => 'mysql', '--force' => true]);
    echo $code === 0 ? "SCHEMA_REBUILD_OK\n" : "SCHEMA_REBUILD_FAILED\n";
    exit($code);
} catch (Throwable $error) {
    fwrite(STDERR, "Reconstrucción detenida; no mostrar trazas ni credenciales.\n");
    exit(1);
}
'@ | & 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1
```

Este script solo reconstruye ESQUEMA. No incorpora --seed deliberadamente:
la inicialización corregida debe aprobarse y ejecutarse con guardias idénticos
después, sin reactivar HTTP entre ambos pasos. Si falla, no reabrir el ERP ni
reintentar borrados automáticamente. Ninguno de estos comandos se ejecutó.

## Verificación posterior propuesta

- Comparar salon_erp_test con línea base anterior: debe permanecer intacta.
- Desarrollo: 74 migraciones, 78 tablas esperadas y FK appointments.service_id
  a services.id, NOT NULL, restrictiva; cero citas/ventas/pagos al terminar bootstrap.
- Roles únicos por nombre; propietario admin@salon.com activo, role=alvaro,
  role_id coherente, hash válido, PIN configurado sin imprimirlo. Login real,
  redirección al panel y comprobación de roles denegados. Confirmar empleados
  vinculados antes de probar RRHH.
- Dos servicios demo y productos con tipos explícitos, sin stock ni volumen
  inicial implícito. Fórmulas con referencias correctas. Cuentas 3.3 y otras
  requeridas presentes, 7.2 bloqueada y cuentas base protegidas.
- Configurar NIO/USD, tasa vigente cuando se use USD, bóvedas/bancos si se
  requieren; ningún saldo inicial inventado. Verificar períodos y catálogo.
- Con autorización para nuevas filas de comprobación: alta con servicio
  exclusivo de services, agenda hoy/futura, confirmación ID/fecha, sesión/CSRF,
  varios estilistas y facturación NIO de servicio sin insumos con caja abierta.
  Verificar vínculos y cuadre; no borrar físicamente la prueba financiera.
- Mantener SALON_TEST_DB_RESET_ALLOWED=0; no usar PHPUnit contra desarrollo.

## Recuperación

Mantener escritores detenidos. Verificar SHA256 y obtener autorización para
restaurar en un destino vacío explícito. No superponer el dump sobre tablas
nuevas: no lleva DROP TABLE ni CREATE/USE DATABASE. Primero ensayar en una base
de recuperación distinta y verificar conteos, FKs, hashes, autenticación y
venta 106. No modificar salon_erp_test para ello.

El dump restaura el estado anterior con 73 migraciones y FK a items; NO deja
H13 aplicado. Código y esquema deben volver a una versión compatible o
planificarse otra reconstrucción. DDL y seeders no son una única transacción:
la recuperación no equivale a ejecutar migrate:rollback. Restaurar puede
descartar escrituras posteriores; no hacerlo automáticamente. Conservar .env,
APP_KEY, archivos y código necesarios para interpretar ese respaldo.

## Autorizaciones pendientes

1. Preparar los cambios mínimos de inicialización señalados, sin cambiar reglas
   financieras ni migraciones históricas, y ensayarlos en un destino separado.
2. Ensayar restauración del respaldo en otro destino, si se exige prueba de recuperación.
3. Tras revisar los resultados: autorizar explícitamente migrate:fresh SOLO
   sobre salon_erp y su bootstrap acordado. La aceptación de datos prescindibles
   no sustituye esa autorización de ejecución.

En esta fase solo se escribió este documento y el respaldo SQL/SHA256 externo.
No se cambió código, configuración, seeders, permisos, usuarios SQL ni datos;
no hubo pruebas, migraciones ni commits. Indicador de pruebas conservado en 0.
