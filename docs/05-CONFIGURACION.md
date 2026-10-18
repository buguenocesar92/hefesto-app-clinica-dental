# 05 — Configuración

Cada variable, y por qué existe. Lo que no está aquí, no existe.

> Los valores de los `.env.example` están **vacíos a propósito**. Ninguna clave
> real se versiona. Ver [04](04-INSTALACION.md).

---

## Los tres archivos

| Archivo | Quién lo lee | Qué tiene |
|---|---|---|
| `.env` (raíz) | Docker Compose y Caddy | dominio, correo de certificados, credenciales de PostgreSQL |
| `api/.env` | Laravel | base de datos, Redis, Authentik, reglas de la aplicación |
| `web/.env` | Nuxt, solo en desarrollo | dirección de la API |

La composición **no** lee `web/.env`: el compose pasa `NUXT_API_BASE` como
variable de entorno del servicio. El archivo está para cuando se levanta la
interfaz fuera de Docker.

---

## Aplicación

| Variable | Por omisión | Para qué |
|---|---|---|
| `APP_NAME` | `ClinicaDental` | nombre en los registros |
| `APP_ENV` | `local` | `production` en producción |
| `APP_DEBUG` | `false` | **tiene que ser `false` en producción**. Con `debug`, una excepción en pantalla puede incluir contenido de una petición |
| `APP_TIMEZONE` | `America/Santiago` | zona horaria de los consultorios |
| `APP_URL` | `http://localhost:8000` | URL pública de la API |
| `APP_KEY` | (vacía) | se genera con `key:generate`. **El contenedor no arranca sin ella** |

### Registro

| Variable | Por omisión | Para qué |
|---|---|---|
| `LOG_CHANNEL` | `stack` | canal principal |
| `LOG_STACK` | `single,stderr` | `stderr` es la que leen los contenedores |
| `LOG_LEVEL` | `info` | **`debug` deja el cuerpo de las peticiones en el registro.** Con datos de salud, en producción tiene que ser `info` o más alto |

---

## Base de datos

| Variable | Por omisión | Para qué |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | **obligatorio**. La RLS no existe en otro motor |
| `DB_HOST` | `postgres` | nombre del servicio en Compose |
| `DB_PORT` | `5432` | |
| `DB_DATABASE` | `clinica_dental` | |
| `DB_USERNAME` | `clinica_dental` | |
| `DB_PASSWORD` | (vacía) | misma clave que en el `.env` de la raíz |
| `DB_SSLMODE` | `prefer` | `prefer` sirve dentro de la red de Compose. Si la base queda afuera, `require` |

### La conexión de la bitácora

| Variable | Por omisión | Para qué |
|---|---|---|
| `DB_AUDITORIA_HOST` | el de `DB_HOST` | |
| `DB_AUDITORIA_PORT` | el de `DB_PORT` | |
| `DB_AUDITORIA_DATABASE` | el de `DB_DATABASE` | |
| `DB_AUDITORIA_USERNAME` | el de `DB_USERNAME` | |
| `DB_AUDITORIA_PASSWORD` | el de `DB_PASSWORD` | |
| `DB_AUDITORIA_SSLMODE` | el de `DB_SSLMODE` | |

**Por qué aparte si por omisión es la misma base**: la petición que se deniega
termina revertida, y el registro del intento tiene que sobrevivir a esa reversa.
Con la misma conexión se perdería justo el registro que más importa.

Se pueden separar físicamente: es una de las separaciones que suele pedir una
auditoría externa. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Redis

| Variable | Por omisión |
|---|---|
| `REDIS_CLIENT` | `predis` |
| `REDIS_HOST` | `redis` |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | (vacía) |
| `REDIS_DB` | `0` |
| `REDIS_CACHE_DB` | `1` |

Y las tres de Laravel:

| Variable | Por omisión | Para qué |
|---|---|---|
| `CACHE_STORE` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | si el worker no corre, las tareas quedan apiladas |
| `SESSION_DRIVER` | `database` | las sesiones viven en la base, no en Redis |

---

## Sesión

| Variable | Por omisión | Para qué |
|---|---|---|
| `CLINICA_DENTAL_EXPIRACION_SESION` | `120` | minutos. **En un consultorio conviene poco**: una sesión abierta mucho rato es una sesión abierta al historial odontológico |
| `SESSION_SECURE_COOKIE` | `false` | **`true` en producción**, cuando el despliegue es por HTTPS |
| `SESSION_SAME_SITE` | `lax` | |

---

## Authentik (identidad)

| Variable | Por omisión | Para qué |
|---|---|---|
| `AUTHENTIK_ISSUER` | (vacía) | URL del emisor, **sin barra final**. El descubrimiento OIDC se lee de `<emisor>/.well-known/openid-configuration` |
| `AUTHENTIK_CLIENT_ID` | (vacía) | cliente OIDC **exclusivo de esta aplicación**, para poder revocarle el acceso sin tocar las demás |
| `AUTHENTIK_CLIENT_SECRET` | (vacía) | |
| `AUTHENTIK_REDIRECT_URI` | (vacía) | **tiene que coincidir exactamente** con la URL de retorno en Authentik |
| `AUTHENTIK_ALGORITMO` | `RS256` | |
| `AUTHENTIK_AUDIENCE` | el `CLIENT_ID` | audiencia esperada del `id_token` |
| `AUTHENTIK_LEEWAY` | `60` | margen de tiempo, en segundos |

### Equipos y rol

| Variable | Por omisión | Para qué |
|---|---|---|
| `AUTHENTIK_GRUPO_POR_CONSULTORIO` | `ClinicaDental` | prefijo de los equipos |
| `AUTHENTIK_EQUIPO_RECEPCION` | `Recepcion de ClinicaDental` | |
| `AUTHENTIK_EQUIPO_PROFESIONAL` | `Profesionales de ClinicaDental` | |
| `AUTHENTIK_EQUIPO_ADMINISTRADOR` | `Administradores de ClinicaDental` | |

### El mapa de equipos

El equipo completo de una persona se arma con el prefijo y dos puntos:

| Equipo | Otorga |
|---|---|
| `ClinicaDental:Recepcion de ClinicaDental` | rol recepción |
| `ClinicaDental:Profesionales de ClinicaDental` | rol profesional |
| `ClinicaDental:Administradores de ClinicaDental` | rol administrador |
| `consultorio-<slug>` | **el consultorio**: el `tenant_id` |

**El equipo del consultorio es el que no sigue el patrón.** Se llama
`consultorio-casa-central`, con guion y no dos puntos. Eso es a propósito: la
aplicación lo lee por el prefijo `consultorio-` para no confundir un equipo de
rol con un equipo de consultorio.

### Por qué el rol se resuelve en cada ingreso

Si en Authentik le quitan el equipo a alguien, en ClinicaDental pierde el acceso
en la sesión siguiente. Guardar el rol para siempre en la base deja ventanas de
acceso que nadie cerraba.

`usuarios.rol` es una **copia de trabajo**, no la fuente de verdad.

---

## Reglas de la aplicación

Van en `config/clinica-dental.php`.

### Agenda

| Variable | Por omisión | Para qué |
|---|---|---|
| `CLINICA_DENTAL_DURACION_MAXIMA_MINUTOS` | `240` | red de seguridad para cuando el catálogo tiene un dato raro |
| `CLINICA_DENTAL_ANTICIPACION_MAXIMA_DIAS` | `180` | hasta cuándo se puede pedir agenda |
| `CLINICA_DENTAL_MARGEN_ENTRE_CITAS` | `0` | minutos entre una cita y la siguiente |

### Alergias

| Variable | Por omisión | Para qué |
|---|---|---|
| `CLINICA_DENTAL_VIGENCIA_DESBLOQUEO_DIAS` | **vacía** | días que dura un desbloqueo |

**Vacía significa que el desbloqueo no vence**: vale hasta que un profesional lo
cierre a mano, que es la conducta por omisión.

No hay un valor por omisión en el software, y esa es una decisión: un plazo
inventado sería exactamente el tipo de dato que después nadie sabe defender. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

### Odontograma

| Variable | Por omisión | Para qué |
|---|---|---|
| `CLINICA_DENTAL_ALTA_ODONTOGRAMA_AUTOMATICA` | `true` | crear el mapa de 32 piezas al primer vistazo del paciente |
| `CLINICA_DENTAL_ORIGEN_ODONTOGRAMA` | `nuevo` | `nuevo` o `migracion` |

Que se cree al primer vistazo es una decisión de producto: en un consultorio el
mapa siempre está, y obligar a que alguien lo cree a propósito solo produce mapas
que nunca se crean.

---

## CONSERVACIÓN: esta decisión es del cliente, no del software

**Esta es la parte más delicada de esta configuración. Léala antes de cambiar
nada.**

| Variable | Por omisión |
|---|---|
| `CLINICA_DENTAL_RETENCION_BITACORA_DIAS` | **vacía** |
| `CLINICA_DENTAL_RETENCION_INTERVENCIONES_DIAS` | **vacía** |
| `CLINICA_DENTAL_AVISAR_ANTES_DE_PURGAR` | `false` |

### Qué significa "vacía"

**"No hay plazo configurado."**

Que es exactamente lo que dice el campo, y no un número inventado con buena
intención.

### Por qué nace vacía

La Ley 19.628 obliga a que la conservación de datos personales tenga una finalidad
declarada, pero **no fija un plazo único para las fichas clínicas**, y su
articulado no se pudo verificar en el entorno donde se escribió esta aplicación.
Ver el aviso de [07](07-SEGURIDAD-Y-LEY-19628.md).

### La consecuencia, y es deliberada

Con el plazo en `null`, **la aplicación no purga nada por su cuenta**.

Borrar historia clínica porque pasó un número inventado sería peor que no borrar
nunca: la historia clínica no se reconstruye.

### Cuándo completarla

Solo cuando el consultorio cliente, **con su revisión legal**, tenga un plazo
escrito en su documento de tratamiento de datos. El valor va en **días**.

---

## Alertas

| Variable | Por omisión | Para qué |
|---|---|---|
| `CLINICA_DENTAL_ALERTA_DENEGADOS_POR_HORA` | `20` | umbral de revisión |
| `CLINICA_DENTAL_ALERTA_DENEGADOS_POR_USUARIO` | `5` | umbral por persona |

**Los umbrales existen. El aviso automático NO está implementado**: no hay
correo saliente en esta aplicación. La revisión es manual, y la pantalla de
bitácora lo dice explícitamente (`aviso_automatico_implementado: false`).

Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Interfaz

| Variable | Dónde | Por omisión | Para qué |
|---|---|---|---|
| `NUXT_API_BASE` | `web/.env` o Compose | `http://api:8000` | dirección interna de la API. **Nunca sale hacia el navegador** |
| `NUXT_DIAS_AGENDA` | `web/.env` | `1` | rango de la agenda por omisión |
| `NITRO_PORT` | Compose | `3000` | |

`apiBase` está en `runtimeConfig`, no en `runtimeConfig.public`, justamente para
que no llegue al cliente. El navegador nunca ve la dirección de la API.

---

## Caddy

| Variable | Dónde | Para qué |
|---|---|---|
| `DOMINIO` | `.env` de la raíz | el dominio público |
| `ACME_EMAIL` | `.env` de la raíz | para los certificados |

---

## Lo que no hay, y por qué

| No hay | Por qué |
|---|---|
| Una clave por persona | la identidad la emite Authentik |
| Un reinicio de contraseña | se hace en Authentik. La tabla de tokens existe en el esqueleto de Laravel, pero **no debe habilitarse** sin una decisión de seguridad previa |
| Un secreto de firma de PDF | la firma electrónica es DocuSeal, que ya existe en el catálogo de Hefesto |
| Un mapa de roles configurable | el permiso depende del rol, no de una bandera. Ver [03](03-MULTITENANCY-Y-ROLES.md) |
| Zona horaria por consultorio | es un dato de infraestructura. Si alguna vez hace falta una instalación fuera de Chile, es una migración, no una variable |

---

→ [04](04-INSTALACION.md) para el orden de los pasos.
→ [07](07-SEGURIDAD-Y-LEY-19628.md) para lo que la configuración no alcanza a
proteger.