# H13-C — Ensayo de instalación limpia

Estado final, 13 de noviembre de 2026: instalación, inicialización y verificaciones
HTTP aprobadas. Ambas bases originales conservan exactamente contenido y esquema.
Desarrollo no fue reconstruido. La cuenta y base desechables se conservan.

## Alcance y destino

Ensayo autorizado únicamente sobre `salon_erp_bootstrap_check`, con la cuenta
`salon_erp_bootstrap_runner@localhost`. MySQL 8.4.3, TCP 127.0.0.1:3306,
PHP CLI 8.3.30 de Laragon. No se reconstruye desarrollo ni se ejecuta PHPUnit.

Antes de preparar el destino se verificó que no existían el esquema ni ninguna
cuenta con ese nombre. La administración creó exclusivamente ambos y otorgó
SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX y REFERENCES sobre
el esquema exacto. Con partial_revokes=0 se escaparon los guiones bajos del
patrón del GRANT. Sin roles, privilegios globales efectivos ni GRANT OPTION.
USAGE global no concede acceso a datos.

El runner comprueba destino, CURRENT_USER, puerto, CURRENT_ROLE y SHOW GRANTS
en cada arranque; SELECT sobre users en ambas bases originales es rechazado
con 1142. No se ejecutan migraciones ni seeders como root.

## Aislamiento del proceso

Los auxiliares de `tests/Support/bootstrap-check-*.php` tienen allowlist propia;
no modifican tests/bootstrap.php, TestDatabaseSafety ni las reglas de PHPUnit.
El bootstrap omite .env ordinario, rechaza APP_BASE_PATH ajeno y cachés de
configuración/rutas, restringe las conexiones disponibles a mysql y verifica
la configuración resuelta. Usa APP_KEY aleatoria independiente sin cambiar
la clave del ERP. Sesión SQL en el esquema de ensayo y cookie exclusiva.

Cache array, correo array, cola sync y logging null; sin servicios externos.
El servidor HTTP auxiliar escucha solo en 127.0.0.1:8766 y valida Host y origen.
No sirve archivos privados del workspace. El PHP indicado se usa por ruta absoluta.

La contraseña SQL generada por MySQL y APP_KEY se conservan exclusivamente en
`storage/app/private/bootstrap-check-credentials.json`, ignorado por Git y con
ACL restringida a las identidades locales de ejecución. No se imprimen ni se
incluyen en argumentos. No introducir allí credenciales de desarrollo.

## Instalación ejecutada

Una única instalación inicial, sin migrate:fresh ni segundo intento de DDL:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/bootstrap-check-runner.php install
```

El runner exige esquema vacío y reserva un marcador exclusivo antes de llamar
a `migrate --database=mysql --force` mediante el kernel del entorno aislado.
Resultado: código 0, 74 migraciones aplicadas y ninguna pendiente; FK de
appointments.service_id a services.id.

DatabaseSeeder ejecutado dos veces: igualdad de conteos y hashes del contenido
de TODAS las tablas después de repetirlo. Cinco roles únicos, 63 cuentas,
3.3 patrimonio/deudora protegida y 7.2 inactiva/no imputable; usuarios cero.
Sin ventas, pagos, compras, lotes, kárdex, asientos ni saldos inventados.

Evidencia local ignorada: bootstrap-check-install.json,
bootstrap-check-essential.json y bootstrap-check-console.log, bajo
storage/app/private. Los marcadores evitan repetir automáticamente la
instalación o las escrituras funcionales ya iniciadas.

## Propietario y verificación funcional

Completado en WezTerm mediante PHP desde cmd. El propietario confirmó que
contraseña y PIN permanecieron ocultos durante la entrada y sus confirmaciones.
Evidencia local owner y functional con passed=true; el runner alcanzó su salida 0.
Las ventanas fallidas anteriores no equivalen a un fallo de las migraciones.

El modo owner ejecuta el comando real salon:propietario con confirmaciones
ocultas, sin secretos en argumentos. Luego verifica hashes, rol y expediente;
ejecuta DemoSeeder dos veces y compara el contenido íntegro de las tablas.
Pidió nuevamente la contraseña de ensayo, oculta, para comprobar login HTTP.

Resultados HTTP comprobados, sin PHPUnit ni bypass del middleware CSRF:

| Comprobación | Resultado |
| --- | --- |
| Login real del propietario y del estilista demo | 302 a página autorizada; sesión posterior válida |
| Agenda y clientes con sesión del propietario | 200 |
| Clientes/listado de citas sin sesión | 401 |
| Crear cita anónima con CSRF válido | 401 |
| Crear cita autenticada sin CSRF o con CSRF incorrecto | 419; ninguna cita persistida |
| Crear dos citas del mismo estilista a horas diferentes | 201 ambas; IDs 1 y 2 |
| Servicio solo en services, sin producto del mismo ID | Servicio 3 aceptado; FK correcta |
| Fecha seleccionada 2026-11-14 | Listado con ambas citas y fecha visible en HTML |
| Día actual por defecto | Ninguna cita futura en el listado de hoy |
| Fecha imposible | 422 |
| Borrado de la cuenta base 3.3 | 403 |
| Estilista: agenda / catálogo contable | 200 / 403 |

DemoSeeder se repitió sin diferencias en contenido ni conteos: cinco usuarios
con cinco expedientes y roles coherentes, dos productos con existencia/volumen
abierto cero, dos servicios y una fórmula con referencias deterministas.

El ensayo funcional dejó solo en el esquema desechable un cliente, un servicio
adicional y dos citas con sus vínculos. La contraseña temporal del estilista demo
se restauró en finally. Conteos finales: 78 tablas, 74 migraciones, cinco roles,
cinco usuarios, cinco empleados, dos artículos, tres servicios, una fórmula,
un cliente, dos citas y dos asociaciones de estilistas. Sesiones de ensayo
persistidas exclusivamente en ese esquema. No se ejecutó venta u operación
financiera; documentos financieros, lotes, kárdex y saldos permanecen vacíos.

Evidencia adicional ignorada: bootstrap-check-owner.json,
bootstrap-check-demo.json, bootstrap-check-functional.json y
bootstrap-check-final.json. bootstrap-check-failure.json conserva el fallo
histórico previo a la ejecución final aprobada; no debe interpretarse como
el estado final del ensayo.

## Incidencias de terminal

La identidad de sandbox y el usuario real de WezTerm son distintos. Se corrigió
el acceso de lectura del usuario real al archivo privado del ensayo, sin cambiar
usuarios ni permisos SQL. Se detectó además expansión prematura de una variable
del lanzador. Windows rechazó después un script PowerShell por ExecutionPolicy;
no se cambió esa política. Se sustituyó el lanzador por PHP directamente desde
cmd dentro de WezTerm. Ninguno de estos reintentos repite las migraciones.

ConfigureOwner muestra solo clase, archivo y línea de una excepción, nunca su
mensaje o traza. El runner registra estos metadatos y etiquetas de aserciones
fallidas para diagnosticar sin solicitar credenciales ni transcripciones.

## Integridad y límites pendientes

Línea base previa: salon_erp 78 tablas, 643 registros, 73 migraciones;
salon_erp_test 78 tablas, 107 registros, 74 migraciones. isolation-snapshot.php
admite la fase H13C y exige igualdad de contenido, conteos y esquema de AMBAS
bases al cerrar. La comparación final se registra al terminar el ensayo.
SALON_TEST_DB_RESET_ALLOWED permanece 0: este ensayo no habilita PHPUnit.
Sintaxis PHP de los auxiliares y ConfigureOwner correcta; git diff --check
sin errores. Credenciales/evidencias ignoradas y credenciales fuera del índice
de Git. El puerto HTTP de ensayo quedó cerrado al terminar.

Comparación final ejecutada: ALL_TABLE_COUNTS_CONTENT_AND_SCHEMA_EQUAL=True.
Las 78 tablas de cada base conservan exactamente sus conteos, hashes de todas
las filas y DDL respecto de la línea base. salon_erp sigue con 643 registros y
73 migraciones; salon_erp_test con 107 registros y 74 migraciones. Se detuvo
el servidor HTTP auxiliar al finalizar; no se eliminaron base ni cuenta.

La base y la cuenta desechables se conservan para revisión. No está autorizada
la reconstrucción de salon_erp. El ensayo respalda que migraciones y seeders
permiten inicializar un destino limpio, con acceso y agenda funcionales. El
traslado a desarrollo requiere respaldo vigente de la ventana definitiva,
exclusión de escritores, guardia propia de destino/cuenta y autorización
específica. No se ha probado restaurar el respaldo ni un ciclo financiero
completo aquí; no se declara cobertura de toda la aplicación ni prueba visual
completa del navegador. No se verificó login mediante PIN: solo su almacenamiento
como hash y entrada oculta.

## Comandos de ejecución y cierre

Se ejecutó el modo owner una vez completado el seeding esencial, en terminal
real con el directorio del proyecto seleccionado:

```text
E:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/bootstrap-check-runner.php owner
```

Líneas base y cierre, consultas SELECT/SHOW sin Laravel:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php before H13C
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php after H13C
```

El servidor auxiliar usado, ya detenido:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 -S 127.0.0.1:8766 -t public tests/Support/bootstrap-check-http.php
```

No repetir install ni owner sobre este esquema: contiene los datos del ensayo.
La preparación administrativa también rechaza su reutilización. Para otra
instalación se debe acordar un destino nuevo o autorizar explícitamente la
limpieza; nunca retirar los marcadores para forzar el reintento.

## Limpieza futura — no ejecutada

Requiere autorización nueva, detener únicamente el servidor auxiliar identificado,
confirmar otra vez los nombres y revisar si se necesita exportar el ensayo.
Después, un administrador puede ejecutar exclusivamente:

```sql
DROP DATABASE `salon_erp_bootstrap_check`;
DROP USER 'salon_erp_bootstrap_runner'@'localhost';
```

Retirar después solo credenciales y evidencias privadas de este ensayo según
la política de conservación acordada. No borrar respaldos, bases originales ni
otros archivos privados por comodines. Revertir código auxiliar no elimina
la base ni su cuenta; no ejecutar limpieza automática.

## Archivos de esta fase

Nuevos: bootstrap-check-prepare.php, bootstrap-check-environment.php,
bootstrap-check-runner.php, bootstrap-check-http.php y
bootstrap-check-functional.php en tests/Support, y este documento.
Modificados: tests/Support/isolation-snapshot.php (fase H13C) y
app/Console/Commands/ConfigureOwner.php (diagnóstico sin secretos).
docs/H13_B_INICIALIZACION.md enlaza este cierre conservando su informe histórico.
Los cambios anteriores H12/H14-A/H13/H13-B permanecen sin commits.
