# H12-R — Seguridad de API de clientes y citas

Revisión: 20 de noviembre de 2026. Alcance exclusivo de H12. Sin commits,
migraciones, despliegues ni cambios de datos.

## 1. Estado actual de H12

El acceso anónimo descrito el 11 de noviembre en
`AUDITORIA_MANTENIBILIDAD.md`, apartado H12, **ya fue corregido**. No se
reproduce en el registro actual de rutas: los resources se trasladaron al
grupo `web` + `auth` de `routes/web.php:66`, conservando sus URL. `routes/api.php`
solo contiene el comentario de compatibilidad y no registra resources públicos.

La divergencia de catálogo también está corregida en código: alta y edición
de cita validan `exists:services,id` (`CitaController:29,81`), y
`Cita::servicio()` relaciona `Servicio` mediante `service_id`.

La autorización vigente es general para usuarios autenticados del ERP, no
una autorización individual por cliente/cita. Esto coincide con el nivel 1
de `routes/web.php:80`, AGENTS.md §6.3 y la decisión documentada en
`docs/H12_API_AGENDA.md`. No se encontraron Policies, Gates ni llamadas a
`authorize()` para estos recursos. No se presenta `auth` como una Policy
por recurso ni como una restricción a administradores.

No se confirmó una regresión de H12 que justifique cambiar código funcional.
Se añaden únicamente pruebas focalizadas y este informe.

## 2. Rutas afectadas y vigentes

| Ruta | Acciones | Implementación y acceso actual |
| --- | --- | --- |
| `/api/clients` | GET, POST | `ClienteController::index/store`, web + auth |
| `/api/clients/{client}` | GET, PUT/PATCH, DELETE | `show/update/destroy`, web + auth |
| `/api/appointments` | GET, POST | `CitaController::index/store`, web + auth |
| `/api/appointments/{appointment}` | GET, PUT/PATCH, DELETE | `show/update/destroy`, web + auth |
| `/api/appointments/{appointment}/details` | PUT | `updateDetails`, web + auth |
| `/clientes`, `/clientes/{id}` | GET/POST; GET/PUT/DELETE | Mismo controlador y acceso general |
| `/agenda` | GET | Vista y catálogos bajo web + auth |
| `/clientes/grupos` | POST | Grupo `role:admin`; bypass vigente de `alvaro` |
| `/api/items`, `/api/items/{item}` | Resource | Web + auth; scaffold sin implementación |

Las rutas `/clientes/*` y `/api/clients/*` son alias funcionales con URL
distintas, no dos registros públicos de la misma URL. La edición de datos de
citas y su transición de estado son operaciones distintas desde H15/H16.
`CitaController::destroy` sigue vacío: no implementa borrado. No se habilitó
esa operación ni se retiraron rutas. Los métodos vacíos no justifican asumir
ausencia de consumidores externos.

`GET /api/clients` conserva su respuesta Blade, incluso siendo un apiResource;
`show` produce JSON con `Accept: application/json`. No se cambió este contrato.

## 3. Consumidores legítimos identificados

- `resources/views/appointments/index.blade.php:243,302,365`: GET de precarga,
  POST de reserva, PUT de datos con `edit_token` y PUT de estado. Usa
  `credentials: same-origin`, Accept JSON y CSRF en mutaciones.
- `resources/views/clients/index.blade.php`: altas y ediciones por `/clientes`,
  con JSON y CSRF; la gestión de grupos usa `/clientes/grupos`.
- `resources/views/clients/show.blade.php` y el menú de
  `resources/views/layouts/app.blade.php`: navegación web de clientes.
- Pruebas y auxiliares anteriores consumen las URL API: `ApiAgendaSecurityTest`,
  `AppointmentEditingTest`, `AppointmentServicesTest`, `AgendaNavigationTest`,
  `InitializationSeederTest`, `bootstrap-check-functional.php` y auxiliares H13-D.
  Son evidencia de contratos de verificación, no consumidores de producción.

Se buscaron URL literales, nombres de resources, `fetch`, referencias a
controladores y documentación en app, routes, resources, tests, README y docs.
No se identificó un consumidor frontend interno de `/api/clients` o `/api/items`.
Esto **no demuestra que nadie los use**: no se inspeccionaron aplicaciones
externas ni registros de tráfico. Se preservaron todas sus URL y nombres.

## 4. Autenticación actual

`config/auth.php` define el guard `web`, driver `session`, proveedor Eloquent
`Usuario`. El middleware `auth` utiliza el guard predeterminado. La instalación
de Sanctum en Composer no convierte estas rutas en endpoints con bearer tokens.
No se añadió Sanctum, otro guard ni dependencia.

El grupo `web` proporciona cookies, sesión y `PreventRequestForgery` de Laravel.
`bootstrap/app.php:23` fuerza respuestas JSON de errores en `api/*`, aun sin
Accept JSON. Según ese flujo, una lectura sin sesión produce 401 JSON; una
mutación sin token puede ser rechazada antes con 419. Un token CSRF correcto
no autentica al usuario. Las páginas web conservan la redirección de login.

El portal usa la clave de sesión `portal_token` y su propia verificación en
`PortalColaboradorController`; por sí sola no autentica el guard web. El test
comprueba que ese token y un bearer arbitrario no permiten pasar `Authenticate`.
No se usaron credenciales reales.

## 5. Reglas de autorización

| Recurso/acción | alvaro | admin | contador | recepcion | estilista |
| --- | --- | --- | --- | --- | --- |
| Clientes: listar, ver, crear, modificar, solicitar borrado | Sí | Sí | Sí | Sí | Sí |
| Citas: listar, ver, crear, editar, cambiar estado | Sí | Sí | Sí | Sí | Sí |
| Crear grupo de clientes | Sí | Sí | No | No | No |

El borrado de clientes mantiene guardias por historia de ventas/citas y el
modelo usa SoftDeletes. No se probaron ni ejecutaron borrados SQL.
El bypass de `RoleMiddleware` es exclusivamente `alvaro`; `admin` requiere
enumeración explícita en rutas restringidas. No se modificó ese middleware.

Para clientes/citas no existe actualmente un rol del ERP autenticado al que
se deba denegar acceso según la matriz vigente. Fabricar un 403 para estilistas
o contadores cambiaría permisos legítimos. La prueba de usuario sin permiso
administrativo usa un estilista frente al middleware de grupos y comprueba
403; para la API general, una sesión solo del portal carece de autenticación
web y corresponde rechazarla por autenticación, no inventar un 403.

Esta revisión no certifica una política de mínimo privilegio por campo:
el perfil de cliente contiene datos personales, métricas y CxC para todos los
roles admitidos. No se redujo su payload unilateralmente.

## 6. Integridad y correcciones

No se modifican rutas, controladores, modelos ni servicios. Evidencia actual:

- `CitaController::store/updateDetails` valida cliente, servicio y usuarios
  existentes; estilistas requieren array no vacío, enteros distintos.
- `store` entrega solo datos validados a `AgendaService::reservar`; este crea
  una lista explícita de campos, fija `pendiente` y calcula duración desde el
  servicio. Un `status` extra en el alta se ignora; no se aplica por mass assignment.
- `update` permite solo los cuatro estados existentes y prohíbe campos
  comerciales. `updateDetails` exige huella de 64 caracteres y prohíbe estado
  y duración. Los campos adicionales sin regla quedan fuera del payload validado.
- `ClienteController::reglas/datos` comparte reglas para alta y edición y usa
  una lista permitida. `id`, `deleted_at`, fechas de auditoría, roles y saldos
  enviados no forman parte de los datos persistibles. Crédito y grupo sí son
  campos admitidos por la política actual.
- `AgendaService::reservar/editar` agrupa cita y `estilistas()->sync()` dentro
  de `DB::transaction`, con locks y comprobación de versión/conflictos. No se
  observaron commits intermedios en esos métodos.
- El binding `Cita $appointment` coincide con `{appointment}`. Un ID ausente
  debe producir 404 por binding; aquí se inspeccionó el flujo, sin consultar SQL.

La selección web limita estilistas a activos con rol estilista; el servidor
valida existencia, sin imponer ese mismo filtro de rol/actividad. Se conserva
la política actual de asignación/disponibilidad, conforme al alcance solicitado.
No se atribuye a las pruebas nuevas una comprobación real de rollback o carreras.

## 7. Compatibilidad con H13-D

Se leyeron `H12_API_AGENDA.md`, `H13_CITAS_SERVICIOS.md`, el **estado final** de
`H13_D_RECONSTRUCCION_DESARROLLO.md` y las evoluciones H14-A/H15/H16 relevantes.
Las secciones históricas de parada de H13-D no se trataron como estado vigente.

H13-D documenta reconstrucción terminada y FK
`appointments.service_id -> services.id`. Permanecen la migración
`2026_11_13_000001_point_appointments_to_services.php`, la relación comercial
y la validación contra services. No se revirtió ni repitió ninguna intervención.

La prueba nueva utiliza un verificador de presencia en memoria con IDs
disjuntos: clients=10, services=20, users=30; el producto exclusivo 90 se
rechaza como servicio. Comprueba la regla HTTP real sin duplicar catálogos.
No es una comprobación de la FK instalada hoy: no se abrió conexión SQL ni
se certificó retrospectivamente el estado actual de la base.

## 8. Pruebas y resultados

Nuevo archivo: `tests/Unit/ApiAgendaAccessReviewTest.php`.

**8 pruebas aprobadas, 187 aserciones**, código 0, PHP 8.3.30. Registro real de
`routes/web.php` y `routes/api.php` en un router en memoria; middleware real
`Authenticate`, `SessionGuard`, `RoleMiddleware`, `PreventRequestForgery` y
validación real de entrada de citas. Proveedor de usuarios y existencia de
relaciones simulados; `AgendaService` sustituido por mock. Cualquier intento
de obtener una conexión Eloquent falla mediante un resolver prohibitivo.

Cobertura y límites:

| Caso | Evidencia nueva |
| --- | --- |
| Lectura/alta/edición/borrado de clientes y acceso/alta/edición de citas | Rutas conservan web + auth; anónimo no pasa Authenticate en cada acción |
| Sesión legítima | Cinco roles pasan el guard real con proveedor simulado |
| Principal sin permiso | Portal/bearer sin sesión web rechazados; estilista recibe 403 en administración de grupos |
| Alta y edición de clientes | Reglas y lista permitida compartidas, sin persistencia ni consultas de perfiles |
| Alta/edición de citas | Controlador real filtra payload y llama al servicio con datos válidos |
| IDs inválidos y catálogo | Validación real contra verificador en memoria, antes de llamar al servicio |
| Campos protegidos, estados y versión | Extras excluidos; estados, relaciones y edición inválidos rechazados |
| CSRF | Middleware real rechaza token ausente/incorrecto y admite el de sesión; sin bypass de unit tests |

Las comprobaciones de autenticación y CSRF se ejecutan de forma separada del
dispatch a controladores. No se arrancó el kernel HTTP ni se renderizó su
respuesta final 401/419: los tests comprueban las excepciones reales del
middleware; el formato final se verificó en código de bootstrap. El grupo web
se registra como metadato en el router de prueba, no como una pila HTTP completa.
No hay prueba nueva de lectura de datos persistidos, binding con SQL,
transacciones, rollback ni navegador real.

Comando exclusivo, sin cargar phpunit.xml ni tests/bootstrap.php:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d memory_limit=128M -d max_execution_time=45 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/ApiAgendaAccessReviewTest.php
```

Regresión del consumidor existente:

```powershell
node --test tests/Frontend/agenda.test.cjs
```

**26/26 aprobadas**, código 0. Ejecuta el JavaScript real de Blade con dobles
HTTP/DOM, sin red ni SQL. Incluye URLs, sesión, CSRF, edición, estados y manejo
de 401/403/419/409/422. No equivale a prueba de navegador.

La primera ejecución del test nuevo tuvo un error de teardown al restaurar
un resolver Eloquent inicialmente nulo; se corrigió solo el arnés de pruebas.
La ejecución final pasó completa. `git diff --check` también pasó.

Las pruebas históricas `ApiAgendaSecurityTest`, `AppointmentServicesTest`,
`AppointmentEditingTest` y otras usan RefreshDatabase: **no se ejecutaron**.
Sus resultados históricos no se presentan como resultados de H12-R.

## 9. Bases y evidencia preservadas

Ninguna conexión SQL, lectura/escritura de datos, migración, seeder,
restauración, cambio de permisos ni comando de reconstrucción. No se ejecutó
la suite general ni se cargó `.env.testing`. No se modificó `.env`.

`salon_erp`, `salon_erp_bootstrap_check` y la evidencia de `salon_erp_test`
quedaron fuera de la ejecución. La preservación se basa en no acceder a ellas;
no se realizaron snapshots ni se afirma igualdad de hashes SQL medida hoy.
No se tocaron asientos, cuentas, períodos, inventario, bancos ni evidencia AC-05.
Los tres documentos AC_05B4B no versionados ya estaban presentes al inicio y
se conservaron sin cambios.

## 10. Archivos modificados

Solo dos archivos nuevos en esta intervención:

1. `tests/Unit/ApiAgendaAccessReviewTest.php`.
2. `docs/H12_R_SEGURIDAD_API.md`.

Sin modificaciones funcionales ni operaciones Git de escritura.

## 11. Riesgos y límites pendientes

- No hay autorización diferenciada por acción, propiedad del registro o campo.
  La política general concede acceso a PII y perfil financiero a todos los roles
  autenticados; restringirla exige una matriz de negocio distinta y aprobada.
- No se verificaron consumidores externos, configuración efectiva del servidor,
  cookies desplegadas, overrides del guard ni FK actual mediante SQL.
- `AutenticacionController::login` usa `Auth::attempt($credentials)` sin filtrar
  `is_active`; esta revisión no certifica revocación de cuentas desactivadas ni
  sesiones preexistentes. Es una revisión transversal de identidad pendiente,
  no una regresión demostrada del arreglo de rutas H12. No se cambió el login.
- La atomicidad de cita/pivot permanece demostrada por estructura del código,
  sin nueva ejecución de SQL. Las pruebas históricas requieren un procedimiento
  compatible con preservar la evidencia existente antes de repetirse.

## 12. Siguiente intervención recomendada

Si se requiere reducir acceso a datos personales o revocar cuentas inactivas,
definir primero la matriz explícita de acciones/campos y el comportamiento de
sesiones para todos los consumidores web y API. Ensayar después con una identidad
restringida y fixtures transaccionales, previa verificación de esquema, sin
RefreshDatabase ni reutilizar destructivamente la evidencia histórica.

H12-R termina aquí. No se inicia otra corrección, migración ni despliegue.
