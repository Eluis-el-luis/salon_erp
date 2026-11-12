# Aislamiento de pruebas de Salon ERP

## Estado actual verificado — implementación 0.6 (2026-11-12)

Se autorizó y ejecutó una sola recreación de salon_erp_test mediante el
RefreshDatabase normal y un único caso funcional existente:
Tests\Feature\CatalogoCuentasTest::test_crear_subcuenta_bajo_cuenta_del_sistema.
Resultado: 1 caso aprobado, 5 aserciones, código de salida 0, 329393 ms.
No hubo errores de migración ni reparaciones/reintentos destructivos.

Comando ejecutado con el indicador habilitado temporalmente:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --do-not-cache-result --debug --filter '^Tests\\Feature\\CatalogoCuentasTest::test_crear_subcuenta_bajo_cuenta_del_sistema$' tests/Feature/CatalogoCuentasTest.php
```

El runner cargó 11 casos del archivo, filtró exactamente 1 y ejecutó solo ese
caso. No se ejecutó la suite, Composer test, paralelismo ni migración administrativa.
Se inspeccionaron el setup, endpoint/modelo implicados, migraciones y seeders
invocados (RoleSeeder, ContabilidadBaseSeeder y los seeders de catálogos/RRHH
llamados por las migraciones). No se encontraron conexiones alternativas ni
efectos externos en ese flujo; las escrituras quedaron limitadas al esquema tester.

Preflight: respaldo existente y SHA256 coincidente; identidad PDO del tester,
base/puerto, grants limitados, ausencia de roles y rechazo sobre desarrollo.
Sin cachés de configuración/rutas ni variables heredadas de destino presentes.
El archivo .env.testing se conservó en memoria sin imprimir sus credenciales,
se habilitó el indicador y se restauró el contenido original mediante finally.
Estado final confirmado: SALON_TEST_DB_RESET_ALLOWED=0.

| Base | Tablas antes/después | Filas antes/después | Migraciones antes/después |
| --- | --- | --- | --- |
| salon_erp | 78 / 78 | 633 / 633 | 73 / 73 |
| salon_erp_test | 77 / 78 | 103 / 106 | 71 / 73 |

Las huellas de TODAS las tablas de desarrollo (contenido, estructura y recuentos)
coincidieron con la nueva línea base isolation-06-before.json. El auxiliar de
snapshot admite ahora el argumento de fase 06: compara desarrollo íntegramente
y solo informa el nuevo estado de pruebas, cuyo reemplazo estaba autorizado.
La fase 05 conserva su comparación estricta de ambas bases.

Postcheck PDO: salon_erp_tester@localhost, salon_erp_test, puerto 3306.
73 migraciones registradas, ninguna pendiente respecto a los archivos actuales.
El fixture users y la subcuenta 1.1.1.9 quedaron en cero tras el rollback del test.
MySQL volvió a rechazar SELECT sobre salon_erp.users. No se cambiaron permisos,
usuarios, .env ordinario, módulos funcionales ni otras conexiones del ERP.
El respaldo no se restauró ni sobrescribió.

### Procedimiento seguro para una ejecución posterior

Esta autorización se consumió con ese caso; no habilita otra ejecución.
Requiere nueva autorización de destino y filtro exacto. Revalidar respaldo,
cuenta/permisos, destino y cachés; crear una nueva línea base sin sobrescribir
las anteriores. Cambiar localmente solo el indicador, ejecutar con el PHP
absoluto y filtro autorizado, y restaurar siempre el archivo mediante finally.
Ante error, detenerse sin reparar/recrear/restaurar automáticamente. Comparar
desarrollo y registrar el estado parcial o final del esquema de pruebas.

### Limitaciones pendientes

Solo este flujo de creación de subcuenta quedó probado. No se validaron los
restantes casos del archivo ni ventas, inventario o nómina. El SHA256 del dump
no demuestra restaurabilidad; esa comprobación requiere otra autorización y
otro destino desechable. No se auditó globalmente MySQL: se revisaron permisos
del tester, conexiones del flujo y ambas bases; no se afirma integridad de
bases ajenas no inspeccionadas. El rechazo de 0.5 sigue siendo una prueba con
sustitución defensiva; 0.6 sí ejecutó el RefreshDatabase normal.

## Etapas históricas 0–0.5

Los apartados siguientes conservan las fotografías y restricciones de sus
respectivas etapas. Las menciones a cuenta inexistente, 77 tablas o comandos
todavía no ejecutados describen el pasado, no el estado actual de 0.6.

## Resultado de integración 0.5 (2026-11-12)

Se ejecutó únicamente tests/Feature/IsolationReadOnlyTest.php mediante PHPUnit
12.5.33 y PHP 8.3.30. Comando final (sin suite financiera):

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --do-not-cache-result --debug tests/Feature/IsolationReadOnlyTest.php
```

Resultado final: 1 prueba, 11 aserciones, exit code 0, sin risky. El runner
confirmó tests/bootstrap.php. Laravel inició en testing desde este proyecto y
consultó salon_erp_test como salon_erp_tester@localhost, puerto 3306.
El setUpTraits real de Tests\TestCase rechazó RESET_ALLOWED=0 antes de llamar
al trait: refreshCalls=0. Para defensa adicional se sustituyó refreshDatabase()
por una excepción incondicional y se admitió únicamente el SELECT de identidad
en la conexión del ensayo. No se ejecutó el cuerpo real de RefreshDatabase,
migraciones, truncados, seeders ni escrituras de registros.

La primera ejecución falló por rutas de caché Windows: Laravel interpretaba
E:\ como ruta relativa y duplicaba la raíz. Se corrigieron únicamente las cinco
rutas APP_*_CACHE en el bootstrap de pruebas, usando rutas relativas a la raíz
ya fijada. Un aviso intermedio de handlers globales se corrigió con limpieza
explícita de HandleExceptions en el auxiliar; el resultado final no tiene avisos.

tests/Support/isolation-snapshot.php ejecutó SELECT/SHOW antes y después y
comparó recuentos y huellas SHA256 de contenido y estructura de todas las tablas.
Solo guarda metadatos y huellas, no filas, en
storage/app/private/isolation-05-before.json (ignorado). No sobrescribe baselines.
Su conexión administrativa local se usa solo en este auxiliar de inspección,
nunca en PHPUnit. No ejecutar este auxiliar en otro entorno sin revisar su conexión.

| Base | Tablas antes/después | Filas antes/después | Migraciones antes/después |
| --- | --- | --- | --- |
| salon_erp | 78 / 78 | 633 / 633 | 73 / 73 |
| salon_erp_test | 77 / 77 | 103 / 103 | 71 / 71 |

Todas las huellas coincidieron. El indicador local permaneció en 0. Esta prueba
acredita el arranque integrado y el rechazo del guardia con defensa por sustitución;
no acredita el funcionamiento de migraciones ni la suite financiera. Mantener
ejecución explícita de este archivo: su expectativa es RESET_ALLOWED=0.
Para una primera ejecución destructiva hacen falta autorización independiente
del destino, alcance de tests y cambio local del indicador; conservar y revisar
el respaldo, y no confundir un SHA256 correcto con una restauración verificada.

## Implementación 0.2: raíz y ensayo preparado

El bootstrap compara cualquier APP_BASE_PATH heredado en getenv(), $_ENV y
$_SERVER con la raíz física del proyecto, antes de cargar Composer. Rechaza
vacío, inexistente o diferente; después fija las tres fuentes. TestCase repite
la comprobación antes de parent::createApplication() y comprueba basePath()
después del arranque. No cambia el entorno del ERP fuera del proceso de pruebas.

Se preparó tests/isolation-check.php, sin autoload de Composer, Laravel,
PHPUnit, PDO ni lectura de credenciales. Comprueba aceptación y rechazo de raíz,
configuración e identidad ficticia. No se ejecutó. El comando para una futura
autorización específica del ensayo es:

```powershell
& 'E:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -n -d allow_url_fopen=0 tests/isolation-check.php
```

Este ensayo solo prueba los validadores puros: no demuestra el lifecycle real,
la carga de dotenv, los permisos de MySQL ni la integración con RefreshDatabase.
El indicador RESET_ALLOWED tampoco constituye un modo SQL de solo lectura.

## Respaldo propuesto: comandos NO EJECUTADOS

La inspección 0.1 encontró 77 tablas InnoDB: 67 vacías y 10 con 103 filas
(71 de migrations y 32 de configuración). No asumir que son descartables.
Antes de una recreación, autorizar expresamente respaldo de esquema y datos.
El cliente mysqldump instalado se identificó como versión 8.4.3.

Los siguientes comandos PowerShell crean archivos fuera del repositorio;
requieren autorización antes de ejecutarlos. Usan la cuenta administrativa solo
para exportar: no son credenciales de PHPUnit. El prompt solicita la contraseña
sin incluirla en los argumentos. No usar redirección > de PowerShell para el SQL.

```powershell
$salonBackupDir = 'E:\salon-erp-backups'
$salonDumpExe = 'E:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe'
[void](New-Item -ItemType Directory -Path $salonBackupDir -Force)
$salonBackupFile = Join-Path $salonBackupDir ('salon_erp_test_' + [DateTime]::UtcNow.ToString('yyyyMMdd_HHmmss_fffffff') + '.sql')
$salonPartialFile = $salonBackupFile + '.partial'
if ((Test-Path -LiteralPath $salonBackupFile) -or (Test-Path -LiteralPath $salonPartialFile)) {
    throw 'El destino ya existe; no sobrescribir.'
}
& $salonDumpExe --no-defaults --protocol=TCP --host=127.0.0.1 --port=3306 --user=root --password --single-transaction --quick --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF --column-statistics=0 --routines --events --triggers --hex-blob --default-character-set=utf8mb4 --skip-add-drop-table --skip-add-locks "--result-file=$salonPartialFile" salon_erp_test
if ($LASTEXITCODE -ne 0) {
    throw 'Falló mysqldump. Conservar el .partial para diagnóstico; no usarlo para restaurar.'
}
if ((Get-Item -LiteralPath $salonPartialFile).Length -eq 0) {
    throw 'Respaldo vacío; no continuar.'
}
Move-Item -LiteralPath $salonPartialFile -Destination $salonBackupFile
$salonBackupHash = Get-FileHash -LiteralPath $salonBackupFile -Algorithm SHA256
($salonBackupHash.Hash + '  ' + [IO.Path]::GetFileName($salonBackupFile)) | Set-Content -LiteralPath ($salonBackupFile + '.sha256') -Encoding ascii
Get-Item -LiteralPath $salonBackupFile | Select-Object FullName,Length
```

No hay --databases: el dump no selecciona salon_erp ni crea bases. Contendrá
DDL e INSERT para una futura restauración, pero generar el dump no los ejecuta.
Evitar cambios de esquema durante la exportación: --single-transaction no
protege frente a DDL concurrente. Custodiar el directorio con permisos locales
apropiados; el dump contiene configuración y debe tratarse como dato privado.
El tamaño, exit code y SHA256 comprueban la exportación y su integridad de archivo,
no su restaurabilidad. Validar una restauración requiere otra base desechable y
autorización independiente. No restaurar sobre salon_erp ni sobre las 77 tablas.

## SQL definitivo pendiente (sin ejecutar)

La base ya existe; no es necesario ejecutar CREATE DATABASE. Tras reconfirmar
ausencia del usuario, partial_revokes=0 y mandatory_roles vacío:

```sql
CREATE USER 'salon_erp_tester'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
  ON `salon\_erp\_test`.* TO 'salon_erp_tester'@'localhost';
```

Los SELECT y SHOW GRANTS del procedimiento posterior siguen pendientes para
la nueva cuenta. Confirmar DATABASE(), CURRENT_USER(), @@port desde PHP y
rechazo de SELECT sobre salon_erp.users LIMIT 0; revisar además grants y roles.
Ninguna autorización de cuenta o respaldo autoriza PHPUnit ni recreación.

## Verificación y reversión específica de 0.2

Cambios: tests/bootstrap.php, tests/TestCase.php,
tests/Support/TestDatabaseSafety.php, nuevo tests/isolation-check.php y este
documento. No se alteran módulos, .env ni configuración ordinaria.
Se revisan sintaxis PHP y diff; no se declara demostrada la protección mediante
inspección estática. Para revertir solo 0.2, deshacer las comprobaciones de raíz,
retirar el ensayo y esta ampliación documental, conservando los guardias 0/0.1.
No hay reversión SQL ni respaldos creados en esta fase.

## Estado de esta preparación (2026-11-12)

No se autorizó ejecutar PHPUnit, migraciones, seeders ni SQL de escritura.
Las instrucciones SQL de este documento están PREPARADAS, NO EJECUTADAS.

Inspección mediante SELECT de metadatos:

- MySQL Community Server 8.4.3, puerto 3306, servidor DESKTOP-3SPGBQ9.
- Conexión TCP a 127.0.0.1: USER() y CURRENT_USER() resolvieron root@localhost.
- Existen salon_erp (78 tablas) y salon_erp_test (77 tablas). No se cambiaron.
- No existe salon_erp_tester en ningún Host de mysql.user; tampoco hay roles
  asignados a esa cuenta inexistente.
- skip_name_resolve=0, partial_revokes=0, mandatory_roles vacío.
- Ambas bases tienen utf8mb4 / utf8mb4_0900_ai_ci.
- .env ordinario apunta a salon_erp; no debe cambiarse para ejecutar pruebas.
- No se encontró .env.testing ni caché convencional config.php/routes-v7.php.

Estos datos son una fotografía, no una garantía sobre ejecuciones futuras.

## PHP seleccionado

Usar el ejecutable existente de Laragon, sin modificar el PATH global de WezTerm:

```powershell
$salonTestPhp = 'E:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
& $salonTestPhp --ini
& $salonTestPhp -m
```

Carga php.ini del mismo directorio y tiene mbstring, pdo_mysql, dom, libxml,
xmlwriter, tokenizer, filter y json. El php del PATH es otro: PHP 8.5.8 de
WinGet, sin php.ini, mbstring ni pdo_mysql. No hace falta instalar dependencias.

config/database.php conserva la opción SSL tanto en MySQL como MariaDB:
usa Pdo\Mysql::ATTR_SSL_CA cuando existe y PDO::MYSQL_ATTR_SSL_CA en PHP 8.3.
No cambia host, usuario, contraseña, base ni lógica del ERP.

## Configuración local de credenciales

Después de preparar la cuenta SQL con autorización, copiar manualmente
.env.testing.example a .env.testing. Sustituir solo el marcador de DB_PASSWORD
por la contraseña exclusiva del tester, usando un editor local. No ponerla en
comandos, phpunit.xml, documentación ni mensajes de revisión. Usar comillas
simples de dotenv si la contraseña contiene símbolos de interpolación.

El archivo local solo acepta estas dos claves:

```dotenv
DB_PASSWORD="REEMPLAZAR_LOCALMENTE"
SALON_TEST_DB_RESET_ALLOWED=0
```

.env.testing y sus variantes están ignorados por Git; la plantilla sí se
versiona y no contiene una contraseña real. No reutilizar credenciales del ERP.
No se creó el archivo local durante esta preparación.

El destino está fijado por tests/bootstrap.php y phpunit.xml:

| Propiedad | Valor único permitido |
| --- | --- |
| Entorno | testing |
| Nombre de conexión / driver | mysql / mysql |
| Host / puerto | 127.0.0.1 / 3306 |
| Base | salon_erp_test |
| Usuario configurado | salon_erp_tester |
| Cuenta efectiva esperada | salon_erp_tester@localhost |

No admite DB_URL, sockets, prefijos, conexiones read/write ni opciones PDO
adicionales. La contraseña del entorno del terminal no se usa: solo la del
archivo local explícito. No hay fallback a .env ni a root.

## Protección antes de RefreshDatabase

```text
PHPUnit carga tests/bootstrap.php
  -> exige archivo local y contraseña distinta del marcador
  -> fija variables de proceso, $_ENV y $_SERVER
  -> rechaza cachés de configuración/rutas; no los borra
Tests\TestCase::createApplication()
  -> exige haber pasado por el bootstrap seguro
  -> Laravel carga .env.testing y su configuración
  -> valida destino y conserva solo la conexión mysql en este proceso
Tests\TestCase::setUpTraits() [final]
  -> valida entorno, configuración y conexiones ya resueltas
  -> bloquea traits de recreación/truncado mientras RESET_ALLOWED=0
  -> consulta DATABASE(), CURRENT_USER(), @@port y valida identidad efectiva
  -> parent::setUpTraits()
      -> RefreshDatabase / migrate:fresh, únicamente si se autorizó
```

No se usa un guardia después de parent::setUp(): ya sería demasiado tarde.
Tampoco se depende de beforeRefreshingDatabase() heredado: use RefreshDatabase
en una clase hija puede ocultar el hook del padre.

También se reconocen DatabaseMigrations y DatabaseTruncation. Las conexiones
alternativas se retiran únicamente del contenedor de pruebas; no del ERP.
Se fijan servicios de correo/cache/sesión/broadcast en memoria o nulos y una
clave APP_KEY pública y desechable. No se utiliza la clave real del ERP.

Esta barrera protege el arranque normal de las pruebas que heredan de
Tests\TestCase; no sustituye los permisos del servidor. Código de prueba que
ejecute PDO directo, Artisan directo o SQL expresamente destructivo no queda
autorizado por ella. Revisar nuevos tests antes de ejecutarlos. No ejecutar
Composer test: su script actual también limpia la configuración ordinaria.

## SQL administrativo preparado — NO EJECUTAR SIN AUTORIZACIÓN

El administrador necesita CREATE USER y capacidad de conceder los privilegios
indicados. PHPUnit nunca debe recibir esas credenciales administrativas.

Antes de ejecutar, repetir los SELECT de versión, partial_revokes,
mandatory_roles, mysql.user y mysql.role_edges. Si la cuenta ya existe, detenerse
y revisar permisos; no reutilizarla mediante CREATE USER IF NOT EXISTS.

Para el servidor observado (MySQL 8.4, partial_revokes=0 y sin roles obligatorios):

```sql
-- Administración exclusivamente. Esta base YA existe; IF NOT EXISTS la conserva.
CREATE DATABASE IF NOT EXISTS `salon_erp_test`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Solo si no existe la cuenta. El servidor genera la contraseña.
-- Su resultado es secreto: conservarlo localmente, no compartir la salida.
CREATE USER 'salon_erp_tester'@'localhost' IDENTIFIED BY RANDOM PASSWORD;

-- Los \_ evitan que los guiones bajos sean comodines de otros esquemas.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
  ON `salon\_erp\_test`.* TO 'salon_erp_tester'@'localhost';
```

No hay GRANT OPTION, privilegios globales, roles, permisos sobre salon_erp ni
cuenta con Host='%'. No ejecutar FLUSH PRIVILEGES: CREATE USER/GRANT no lo
necesitan. Si cambia partial_revokes o la resolución de hosts, revisar el SQL
antes de conceder permisos; no ampliar hosts ni usar comodines para sortear errores.

El escape de nombres de esquema en GRANT está documentado en el
[manual oficial de MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/grant.html).

## Verificaciones SQL posteriores, todavía pendientes

Con la sesión administrativa, solo lectura:

```sql
SELECT User, Host, account_locked FROM mysql.user
WHERE User = 'salon_erp_tester';
SHOW GRANTS FOR 'salon_erp_tester'@'localhost';
SELECT FROM_USER, FROM_HOST, TO_USER, TO_HOST FROM mysql.role_edges
WHERE TO_USER = 'salon_erp_tester';
SELECT @@GLOBAL.mandatory_roles, @@GLOBAL.partial_revokes;
```

Debe existir únicamente la cuenta local prevista, sin roles adicionales.
SHOW GRANTS debe mostrar USAGE global y los permisos limitados al esquema exacto,
sin GRANT OPTION. Si hay permisos o roles adicionales, detenerse; no corregirlos
automáticamente con REVOKE/DROP USER sin autorización.

Después de autorización para abrir una conexión con el tester, usar el cliente
existente con prompt interactivo de contraseña, sin pasar el secreto por argumentos:

```powershell
& 'E:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe' --no-defaults --protocol=TCP --host=127.0.0.1 --port=3306 --user=salon_erp_tester --password salon_erp_test
```

En esa sesión, ejecutar solo lectura:

```sql
SELECT VERSION(), DATABASE(), USER(), CURRENT_USER(), @@port;
SHOW GRANTS;
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'salon_erp_test' LIMIT 1;

-- Debe fallar con acceso denegado (p. ej. error 1142), sin leer filas.
SELECT * FROM salon_erp.users LIMIT 0;
```

No sustituir la última comprobación por SHOW DATABASES: la visibilidad de un
nombre de esquema no demuestra permisos de lectura/escritura sobre sus tablas.
El rechazo SELECT, junto con los grants y ausencia de roles/globales, permite
revisar aislamiento sin intentar escrituras en el ERP.

## Ejecución futura — requiere una autorización distinta

Hasta nueva autorización, mantener RESET_ALLOWED=0 y no ejecutar PHPUnit,
Artisan test, migraciones ni seeders. No activar ejecución paralela: la lista
permitida solo contempla salon_erp_test, no bases con sufijos.

Cuando estén verificadas cuenta, permisos y destino, y el usuario autorice
explícitamente recrear salon_erp_test, cambiar localmente
SALON_TEST_DB_RESET_ALLOWED=1. Solo entonces podrá usarse el PHP seleccionado
para invocar directamente vendor/phpunit/phpunit/phpunit con phpunit.xml.
No se incluye un comando ejecutable de migración en este procedimiento.

Al terminar la sesión de pruebas, volver el indicador a 0. Esto no revierte
tablas ya recreadas: es una barrera para el siguiente arranque, no un respaldo.

## Alcance de verificación y reversión

En esta preparación solo se permiten SELECT de inspección, lint de PHP,
análisis estático, validación XML y revisión de Git. No se ejecutó PHPUnit ni
un harness automatizado de los guardias. Su comprobación dinámica de rechazo,
arranque y rollback queda pendiente de autorización; no se declara probada.

Revisar que assertConfiguration y el bloqueo de traits aparecen antes de
parent::setUpTraits, y que los destinos no autorizados lanzan RuntimeException
sin incluir valores de configuración. Los guardias no crean bases ni usuarios.

Para revertir esta entrega, restaurar únicamente .gitignore, config/database.php,
phpunit.xml y tests/TestCase.php al contenido previo, y retirar los archivos nuevos
de esta entrega. No tocar .env, datos ni el informe de auditoría existente.
El guardia antiguo era tardío: si se revierte, mantener suspendidas las pruebas.
Como no se ejecutó SQL de escritura, no hay reversión SQL que realizar.
