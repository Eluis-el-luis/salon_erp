# H13-B — Inicialización reproducible

Actualización H13-C: el ensayo autorizado de instalación limpia ya se realizó
en un esquema adicional. Resultado, límites y comparación de las bases
originales en [H13_C_ENSAYO_LIMPIO.md](H13_C_ENSAYO_LIMPIO.md).
Las referencias a ensayos pendientes que siguen describen el cierre histórico
de H13-B, no el estado actual de H13-C.

Estado: código y pruebas transaccionales verificados. NO se reconstruyó
salon_erp ni se creó otra base, cuenta SQL o permiso. El ensayo completo de
una instalación desde cero sigue pendiente de destino y autorización.

## Estructura sencilla

1. DatabaseSeeder: RoleSeeder, ContabilidadBaseSeeder,
   CuentasComplementariasSeeder, AdminCatalogoSeeder y RrhhBaseSeeder, dentro
   de una transacción. Configuración esencial; no usuarios, demos ni saldos.
2. DemoSeeder: optativo, solo local/testing. Proveedores, artículos, servicios,
   fórmula y cuatro usuarios de ejemplo con sus expedientes. No propietario,
   compras, lotes, kárdex, asientos ni saldo. Requiere primero la configuración
   esencial. Contraseñas aleatorias no publicadas ni recuperables: habilitar
   una credencial conocida después mediante la administración existente.
3. salon:propietario: orden interactiva local con entrada oculta sin fallback
   con eco. Pide nombre, correo, contraseña >=12 caracteres y PIN de 4-6 dígitos,
   con confirmación. No admite secretos como argumentos ni los guarda en archivos.
   Pasa los datos a UserSeeder mediante configuración solo en memoria y la
   limpia en finally. No hay contraseña/PIN predeterminados en UserSeeder.

No se añadió un framework de inicialización, no se cambiaron APP_KEY, .env,
configuración operativa ni migraciones históricas.

## Idempotencia y límites

- Roles por nombre exacto, sin asumir IDs. Si ya hay nombres duplicados, se
  rechaza antes de sembrar; no se fusionan ni reasignan usuarios automáticamente.
- El propietario se crea activo con role=alvaro y role_id resuelto por nombre.
  Se exige coherencia en una cuenta existente; nunca se promueve otro rol ni
  se reactiva silenciosamente. Contraseña/PIN existentes se conservan. Una
  cuenta antigua sin PIN hasheado requiere configuración explícita aparte.
- Se crea Employee mediante el método existente del modelo solo si no existe;
  repetir no cambia contrato, salario ni fecha de ingreso. Lo mismo aplica a
  las cuatro cuentas demo. No se backfillean todos los usuarios del negocio.
- Productos por codigo; proveedor y servicios por sus identidades exactas de
  fixture. ServiceSeeder expone las constantes de sus dos nombres. FormulaSeeder
  exige una sola coincidencia y resuelve su artículo por codigo KER-001, no
  por ID 2 ni por semejanza de nombres entre catálogos.
- Estos nombres son claves declaradas de datos demo, no un algoritmo de
  conciliación de historia. No se remapean citas ni se reinterpreta H13.
- Artículos nuevos: producto_venta/und y producto_interno/ml, stock y volumen
  abierto 0. La capacidad 1000 es metadato, no stock. No hay carga inicial H01-B.
- firstOrCreate conserva precios, fórmulas, proveedores y stock que ya existan.
  Con SoftDeletes se considera también la fila eliminada para no duplicarla;
  no se restaura automáticamente. Una dependencia demo eliminada o ambigua
  requiere revisión; no se suplanta con otra coincidencia.
- Catálogos administrativos y RRHH insertan solo lo ausente; repetir no
  restablece descuentos, tolerancias, calculadoras ni desactivaciones locales.
- Seeding es una operación serial con escritores excluidos, no un proceso
  concurrente de sincronización. No se cambiaron índices ni constraints.

## Contabilidad

Se preservan los códigos, tipos y naturalezas originales, sin saldos ni asientos.
El catálogo base usa helpers privados pequeños para resolver por codigo y
crear solo lo ausente. Las monedas NIO/USD no se duplican. Se elimina la
creación artificial del período agosto 2026: el servicio contable ya crea
el período de la fecha de cada operación; no se modifica dicho servicio.

3.3 se asegura como patrimonio/deudora. 7.2 permanece inactiva y no imputable.
Las cuentas base y complementarias conocidas se marcan is_system_account;
no se protege indiscriminadamente todo el catálogo personalizado. Clasificación
incompatible en una cuenta existente hace fallar la inicialización en vez de
reescribirla. No se alteran reglas financieras, contabilización, FIFO ni nómina.

## Archivos H13-B

Modificados: DatabaseSeeder, RoleSeeder, UserSeeder, ProviderSeeder, ItemSeeder,
ServiceSeeder, FormulaSeeder, ContabilidadBaseSeeder, CuentasComplementariasSeeder,
AdminCatalogoSeeder y RrhhBaseSeeder.

Nuevos: database/seeders/DemoSeeder.php,
app/Console/Commands/ConfigureOwner.php,
tests/Feature/InitializationSeederTest.php y este documento.
Auxiliar isolation-snapshot.php: solo añade la fase H13B.
El diagnóstico H13_RECONSTRUCCION_DESARROLLO.md conserva su contenido histórico
con una nota que remite a esta corrección. Los cambios H12/H14-A/H13 previos
del árbol de trabajo se preservan; no son cambios de esta fase.

## Verificaciones ejecutadas

Antes: inspección de las 74 migraciones, seeders y proveedores de arranque,
sin red, procesos externos ni conexiones SQL alternativas explícitas.
PDO: salon_erp_test / salon_erp_tester@localhost / puerto 3306, privilegios
solo sobre pruebas más USAGE global; lectura de desarrollo denegada con 1142.
Esquema existente: 74 migraciones y FK de citas a services. Sin cachés de
configuración/rutas que redirijan el destino. Respaldo de desarrollo existente
disponible y SHA256 FF0753F2EBF11A9EE50EEB878F7AB3D2738CDACF532A05FDE0DBC0C987145EB5
comprobado. No se creó otro respaldo en esta fase.

Se ejecutó SOLO InitializationSeederTest, con DatabaseTransactions sobre mysql,
sin RefreshDatabase, migraciones, truncados, DDL ni recreación de tablas.
El indicador SALON_TEST_DB_RESET_ALLOWED permaneció 0 durante toda la ejecución.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Feature/InitializationSeederTest.php
```

Resultado final: 9/9 aprobadas, 119 aserciones, código 0 (5358 ms).
Comprueban configuración esencial repetida, propietario/hashes/expediente,
conservación de credenciales y contrato, rechazo sin configuración privada,
orden CLI no interactiva rechazada, demos con IDs desplazados, stock 0 y
ausencia de documentos/saldos, personalizaciones conservadas, roles ambiguos
rechazados, ausencia de promoción de cuentas y cuenta contable incompatible.
La regresión focalizada de H12/H14-A comprueba 401, CSRF real 419 (sin bypass
del runner), alta autenticada, dos citas de un estilista y consulta de fecha;
también las relaciones H13. No equivale a repetir las 17 pruebas anteriores
que usan RefreshDatabase ni a verificar el terminal interactivo real.
Además se verificó el bloqueo HTTP 403 al borrar una cuenta base, la conservación
de su código ante edición y la persistencia de un nombre local al resembrar.

El primer ensayo aprobó 5/6 casos: los contadores AUTO_INCREMENT ya avanzados
hicieron coincidir IDs del fixture que pretendía separarlos. Se corrigió SOLO
ese fixture leyendo metadatos actuales y se amplió la cobertura de rechazos.
La ejecución intermedia aprobó 8/8; la definitiva, con el guardia contable,
aprobó 9/9. Logs ignorados: storage/app/private/h13b-phpunit*.log.

Después: salon_erp mantiene todas las cuentas, hashes de contenido y esquema
de la línea base (78 tablas, 643 registros, 73 migraciones). salon_erp_test
mantiene TODOS los conteos y hashes de contenido (78 tablas, 107 registros,
74 migraciones), sin usuarios ni empleados fixture. MySQL puede consumir
números AUTO_INCREMENT aun con rollback; no se reiniciaron contadores.
Sintaxis PHP y git diff --check correctos. No hay commits ni dependencias nuevas.

## Procedimiento para instalación limpia — preparado, no ejecutado

Antes de cualquier borrado: autorización específica, respaldo vigente probado,
destino/identidad comprobados en el mismo proceso y exclusión de escritores.
No usar las siguientes órdenes aisladamente para elegir el destino: mysql
es el nombre de conexión, no un guardia de base de datos. Conservar el wrapper
de seguridad del plan anterior para la fase de esquema y repetir las mismas
comprobaciones de destino/cuenta antes de cada paso de inicialización.

Secuencia para un destino limpio AUTORIZADO:
1. Aplicar las 74 migraciones con el guardia de destino; H13 recibe citas vacías.
2. Ejecutar DatabaseSeeder esencial, sin demos ni credenciales:
   `php artisan db:seed --database=mysql --class=DatabaseSeeder --force`.
3. En terminal con entrada oculta, PHP 8.3.30 y zend.exception_ignore_args=1,
   ejecutar `artisan salon:propietario`. Guardar la credencial en el gestor
   local del propietario. Si la terminal no permite ocultarla, se rechaza;
   no usar argumentos, eco, scripts con contraseña ni transcripción pública.
4. Opcionalmente ejecutar `artisan db:seed --database=mysql --class=DemoSeeder`
   en local/testing. No crea contraseñas compartidas. No cambiar stock por CRUD.
5. Verificar roles únicos y coherentes, propietario activo, hashes y PIN sin
   mostrarlos, Employee por usuario sembrado, catálogo/3.3/7.2/protección,
   productos vacíos, fórmulas y FKs H13. Confirmar login, sesión y CSRF reales.
6. Configurar datos locales que no se inventan: bancos/bóvedas, tasas y saldos
   mediante sus operaciones autorizadas. H01-B continúa pendiente.

No ejecutar esos pasos ahora sobre salon_erp. Mantener APP_KEY y .env existentes.

## Ensayo completo pendiente

Solicitar autorización separada para crear una base desechable adicional
(nombre propuesto salon_erp_bootstrap_check), sin reutilizar salon_erp ni
salon_erp_test. Acordar la cuenta existente y el destino en un runner con
allowlist propia; los guardias de PHPUnit actuales NO autorizan ese nombre y
no deben debilitarse para hacerlo pasar. No se han creado base/cuenta/grants.

Ensayar allí las 74 migraciones -> DatabaseSeeder -> propietario con entrada
protegida -> demos opcionales -> repetición; verificar además login/roles,
facturación y protección del catálogo. Probar rollback/recuperación y comparar
que las dos bases existentes no cambien. Solo después solicitar autorización
para reconstruir desarrollo con un respaldo nuevo de la ventana final.

## Reversión

Esta fase no dejó filas duraderas de fixtures ni cambió desarrollo. Revertir
solo los parches H13-B de seeders y retirar DemoSeeder, ConfigureOwner, tests
y documento H13-B y su identificador de snapshot. No resetear globalmente ni
retirar los cambios H13/H14-A anteriores. Si la inicialización se aplicó después
en otro destino, revertir código no deshace usuarios/catálogos: requiere plan
de recuperación autorizado. No borrar historia ni restaurar SQL automáticamente.

Los diffs de UserSeeder incluyen líneas de credenciales retiradas del código
anterior: redactarlas antes de compartirlos. El archivo actual no contiene
contraseñas ni PIN predeterminados.
