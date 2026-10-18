# 01 - Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

## Qué es y qué no es

ClinicaDental es una aplicación propia de **Hefesto Studio** para consultorios
dentales en Chile. Agenda con control de bloqueos, odontograma como historia
clínica, registro de alergias con desbloqueo firmado, planes de tratamiento y
administración del consultorio.

> No es un despliegue de una herramienta de terceros ni una reubicación de
> código ajeno. Se usaron Laravel, Nuxt, `firebase/php-jwt`, PostgreSQL y
> Authentik como librerías, que es lo normal: lo que se escribió aquí es la
> aplicación.

---

## Las cuatro piezas

```
navegador
   |
   |  cookie de sesión (HttpOnly, SameSite)
   v
+-------------------------+
| Caddy                   |  termina TLS, único puerto público
|   /api/* → api:8000     |
|   /*      → web:3000    |
+-------------------------+
   |                    |
   v                    v
+------------+    +--------------------------+
| Nuxt 4     |    | Laravel 13               |
| (SSR)      |--->|  rutas  → Policies       |
|            |    |  servicios → SQL         |
| BFF        |    +-----------+--------------+
+------------+                |
                              |  set_config('app.tenant_id', …, true)
                              v
                    +------------------+
                    | PostgreSQL 18    |  RLS forzada por tenant_id
                    | Redis 8          |  cache, sesiones, colas
                    +------------------+
```

### El navegador nunca habla con la API

Habla con el servidor de Nuxt, que es un **BFF** (backend for frontend) y
reenvía la cookie de sesión. Eso elimina la necesidad de un token en el código
del navegador, que es donde los tokens se filtran.

El BFF reenvía además la cabecera `X-XSRF-TOKEN`, leyendo la cookie
`XSRF-TOKEN`. Sin eso, todas las peticiones que modifican datos devuelven 419
y el error es muy difícil de entender.

`web/server/api/[...ruta].ts` es el proxy general: todo lo que llega a `/api/*`
va a `/api/v1/*` de la API. No hay un archivo por endpoint, porque con más de
cuarenta rutas eso es una lista más que mantener y termina faltando un archivo.

### Una sola ruta de API

`bootstrap/app.php` declara `health: '/api/v1/salud'` y `routes/web.php` aplica
el prefijo. Queda a la vista que toda la superficie está versionada en una sola
línea.

---

## El recorrido de una petición

```
1. Caddy termina TLS y agrega cabeceras de endurecimiento.
2. Nuxt recibe la petición del navegador y la reenvía a la API con la cookie.
3. Laravel, en el grupo `web`:
   a. ResolverTenant    abre transacción y fija `app.tenant_id` con alcance
                        de TRANSACCIÓN. Si no hay sesión, pasa sin tocar nada.
   b. Verificación CSRF
   c. auth             confirma la sesión
4. La ruta aplica su middleware:
   - `odontograma.auditado:<accion>`  anota el intento en `accesos_odontograma`,
                                       permitido o denegado, y corta con 404
   - `admin`                           corta a quien no administra
5. El controlador valida la entrada y llama a un servicio o a una Policy.
6. La Policy decide. Si rechaza, responde 404, no 403.
7. El servicio escribe. La RLS comprueba, fila por fila, que la fila pertenece
   al consultorio del contexto.
8. La transacción confirma y el contexto desaparece con ella.
```

El paso 8 es el que importa. El contexto se fija con `set_config(…, true)`, que
tiene alcance de transacción: cuando la transacción termina, el valor se va. En
el worker de colas, que reutiliza la misma conexión para trabajos de consultorios
distintos, un `SET` normal dejaría el consultorio equivocado puesto.

---

## Por qué una imagen con varios procesos

En un consultorio el cuello de botella no es PHP: es esperar a que alguien
responda. Un solo contenedor con `php-fpm`, el worker de colas y el
planificador es más simple de operar, y en una instalación de este tamaño no
cuesta nada frente a separar en varios contenedores.

Si el worker de colas no corre, las tareas quedan apiladas. En este producto eso
significa que nadie se entera de que se agendó una cita ni de que hay una
alergia grave hasta que se abre el correo del operador.

`nginx` **no** está en el `supervisord.conf` a propósito: el `CMD` de la imagen
es `supervisord`. Si nginx fuera un cuarto programa ahí, habría dos
supervisores compitiendo por el mismo archivo de pid. En esta aplicación el
contenedor de la API no pública puertos y Caddy no le manda tráfico HTTP de
usuarios, así que nginx queda como proceso interno.

---

## En producción solo el proxy pública puertos

| Servicio | Puerto publicado |
|---|---|
| `caddy` | 80, 443 (TCP y UDP) |
| `postgres` | ninguno |
| `redis` | ninguno |
| `api` | ninguno |
| `web` | ninguno |

PostgreSQL, Redis, la API y la interfaz no se alcanzan desde afuera. En
desarrollo los puertos están atados a `127.0.0.1`, no a `0.0.0.0`: una máquina de
desarrollo en una cafetería con la misma red no es un lugar para una base con
historias clínicas de ejemplo.

---

## Dónde vive cada permiso

| Capa | Qué hace |
|---|---|
| `routes/web.php` | declara qué recurso se toca y con qué middleware |
| Middleware `admin` | corta las rutas de administración para quien no administra |
| Middleware `odontograma.auditado` | anota el intento en la bitácora, permitido o denegado |
| `App\Policies\*` | **permiso fino**: quién puede ver, crear, editar, cerrar |
| `App\Services\Permisos\CapacidadesDeUsuario` | responde las preguntas de capacidad |
| PostgreSQL | limita qué filas existen para quién consulta |
| Interfaz (Nuxt) | esconde botones según lo que dice la API |

El orden importa: **la base filtra filas, la Policy decide acciones, la
interfaz solo muestra**. Que un botón no aparezca no impide que alguien llame
a la API, y por eso cada ruta vuelve a preguntar al servidor.

→ [03](03-MULTITENANCY-Y-ROLES.md)

---

## Por qué un rechazo responde 404

Un 403 confirma que el recurso existe. En una historia odontológica eso importa
más que en un portal: no se le dice a quien preguntó si el paciente existe en
otro consultorio.

Está configurado en `bootstrap/app.php`, que traduce `AuthorizationException` a
404 para todo lo que cuelgue de `api/v1/*`.

---

## Configuración por dónde

| Variable | Dónde vive |
|---|---|
| Dominio y correo de los certificados | `.env` en la raíz (lo lee Compose y Caddy) |
| Base de datos, Redis, Authentik, reglas | `api/.env` (lo lee Laravel) |
| Mapeo de equipos de Authentik a roles | `config/authentik.php` |
| Reglas de la aplicación | `config/clinica-dental.php` |
| Conexión de la bitácora | `config/database.php`, clave `auditoria` |
| Registro de accesos denegados | `config/logging.php`, canal `accesos_rechazados` |

→ [05](05-CONFIGURACION.md)

---

## Estructura de la API

```
api/app/
|-- Console/Commands/        VerificarAislamientoCommand, CrearConsultorioCommand
|-- Enums/                   RolDental, EstadoCita, EstadoPieza, EstadoPlan, …
|-- Http/
|   |-- Controllers/         Agenda, Pacientes, Odontograma, Planes, Alergias,
|   |                        Administracion, Panel, AuthController
|   |-- Middleware/          ResolverTenant, AuditarOdontograma, SoloAdministrador
|   |-- Requests/            validación de entrada
|   `-- Policies → app/Policies
|-- Models/                  17 modelos, con Concerns/PerteneceAlConsultorio
|-- Policies/                permiso fino, uno por recurso
|-- Providers/               AppServiceProvider: enlaza los servicios de OIDC
`-- Services/
    |-- Agenda/              ServicioAgenda, VerificadorDeDisponibilidad
    |-- Alergias/            RevisorDeObstaclos, ObstacloAtencion
    |-- Auth/                DescubridorOidc, VerificadorIdToken, ProveedorOidcAuthentik
    |-- Fichas/              BitacoraOdontograma
    |-- Odontograma/         ServicioOdontograma, MaquinaDeEstadosPieza, PiezaFdi
    |-- Pacientes/           TiempoSinControl
    |-- Permisos/            CapacidadesDeUsuario
    `-- Tenancy/             ContextoTenant
```

### Los servicios son la lógica; los controladores traducen

Un controlador no decide nada de negocio. Llama a un servicio y devuelve JSON.
La razón está en el orden de las comprobaciones: primero se pregunta por la
alergia, porque es el único bloqueo que no se resuelve buscando otro horario, y
después se pregunta por el choque de agenda. Si el controlador validara
disponibilidad antes de alergias, recepción vería "ese horario no sirve" y lo
intentaría con otro, sin enterarse nunca de que el problema era otro.

---

## Ver o no ver el código

Esta aplicación se diseñó en el mismo patrón que las otras quince de Hefesto, y
no se inventó nada nuevo. Lo que se copió a propósito:

- El aislamiento por `tenant_id` con RLS, de
  `apps/clinica-salud/docs/03-MULTITENANCY-Y-ROLES.md`.
- El historial clínico agrupado por fecha, de `apps/veterinaria`.
- El BFF con reenvío de cookie y token CSRF, de `apps/clinica-salud`.
- Las reglas de respaldo, ajustadas para datos de salud.

Lo que **no** se copió y se decidió distinto:

| Decisión | Por qué |
|---|---|
| Recepción sí ve el mapa dental | Si no lo viera, no podría agendar con criterio. La cronología sigue cerrada. |
| Alergias y condiciones no van auditadas | No son el historial: son la lista de lo que NO se puede hacer. Esa distinción sostiene el diseño. |
| La severidad de una alergia la pone el profesional | El sistema no deduce criterio clínico. Un falso positivo detiene pacientes que sí se pueden atender. |
| No hay plazo legal de retención por omisión | La Ley 19.628 se pudo escribir pero **no verificar**. Un plazo inventado sería un dato que nadie sabe defender. Ver [07](07-SEGURIDAD-Y-LEY-19628.md). |