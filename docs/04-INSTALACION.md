# 04 — Instalación

De cero a la primera persona dentro del sistema.

> **Nada de esto se ejecutó.** No hay PHP, ni Composer ni Docker en el equipo
> donde se escribió esta aplicación. Los comandos de abajo son correctos según
> la documentación de cada herramienta, pero **no se han corrido nunca**. Ver
> [10](10-PRUEBAS-Y-ESTADO.md).

---

## Lo que hace falta

| Herramienta | Versión | Para qué |
|---|---|---|
| Docker con Compose v2 | — | todo lo demás corre adentro |
| Authentik | — | la identidad. Ver `kits/hefesto-identidad-authentik` |
| Dominio con DNS | — | apuntando al servidor donde va la instalación |

**No hace falta** PHP, Composer ni Node en el equipo: todo corre en contenedores.

Sobre las versiones de imagen: están fijadas por etiqueta en los
`Dockerfile` y en los compose (`postgres:18.6`, `redis:8.8.3-alpine`,
`php:8.4-fpm-alpine3.24`, `node:24-alpine3.24`, `caddy:2.11.7-alpine`).
Esas etiquetas se consultaron el 2026-10-17, pero **no se construyeron las
imágenes** para comprobarlas.

---

## Orden de los pasos

El orden importa. Cada paso depende del anterior.

### 1. Los archivos de entorno

Hay **dos** y no son el mismo:

```bash
cd apps/clinica-dental

# El de la composición: dominio, correo de certificados y credenciales de PostgreSQL.
cp .env.example .env

# El de Laravel: todo lo demás.
cp api/.env.example api/.env

# El de la interfaz, solo para desarrollo local sin Docker.
cp web/.env.example web/.env
```

Complete en `api/.env`, como mínimo:

| Variable | Qué poner |
|---|---|
| `DB_DATABASE` | `clinica_dental` |
| `DB_USERNAME` | un usuario nuevo, no `postgres` |
| `DB_PASSWORD` | una clave nueva. **Sin valores reales en este archivo.** |
| `AUTHENTIK_ISSUER` | la URL del proveedor, sin barra final |
| `AUTHENTIK_CLIENT_ID` | el cliente OIDC de esta aplicación |
| `AUTHENTIK_CLIENT_SECRET` | el secreto del cliente |

Y en `.env`:

| Variable | Qué poner |
|---|---|
| `DOMINIO` | el dominio público de la instalación |
| `ACME_EMAIL` | correo para los certificados |

El mismo `DB_PASSWORD` tiene que estar en los dos archivos: Compose lo usa para
levantar PostgreSQL y Laravel lo usa para conectarse.

### 2. Generar la clave de la aplicación

```bash
docker compose -f deploy/docker-compose.prod.yml run --rm api php artisan key:generate
```

Ojo: **después** de copiar `api/.env`, porque el comando escribe ahí.

### 3. Levantar la base

```bash
docker compose -f deploy/docker-compose.prod.yml up -d postgres
docker compose -f deploy/docker-compose.prod.yml ps
```

Espere a que PostgreSQL esté `healthy`. Las migraciones se ejecutan solas en el
arranque de la API.

### 4. Levantar todo

```bash
docker compose -f deploy/docker-compose.prod.yml up -d --build
```

Esto corre las 21 migraciones, compila los caches y levanta los cinco
servicios.

### 5. **Verificar el aislamiento antes de seguir**

Este es el paso que no se saltea:

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan clinica:verificar-aislamiento
```

Debe terminar con `Aislamiento verificado`. Si no, **no siga**: la instalación
no tiene el aislamiento activo y no puede recibir pacientes.

Qué comprueba:

- Que las diecisiete tablas tengan RLS **forzado**.
- Que exista `politica_aislamiento_tenant` en cada una.
- Que un consultorio no vea filas de otro, ni por clave primaria.
- Que no se pueda escribir una fila declarando otro consultorio.
- Que una consulta **sin contexto falle**, en vez de devolver cero filas.

Escribe filas temporales con identificadores fijos y las borra al final. **No lo
corra contra una base con pacientes reales.**

### 6. Crear el consultorio y su primer administrador

`consultorios` es la única tabla sin RLS, y por eso el alta no se puede hacer
desde la aplicación: sería un agujero en el aislamiento. Es un comando:

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan clinica-dental:crear-consultorio \
    --slug=casa-central \
    --nombre="Consultorio Odontológico Casa Central" \
    --nombre-corto=CasaCentral \
    --direccion="Av. Providencia 1234, oficina 802" \
    --telefono="+56 2 2345 6789" \
    --correo="contacto@casacentral.cl" \
    --administrador="Nombre Apellido" \
    --correo-admin=persona@casacental.cl
```

El `--slug` **tiene que** coincidir con lo que se ponga en el equipo de Authentik
(ver el paso siguiente). El comando imprime el identificador del consultorio:
guárdelo.

### 7. Configurar la identidad en Authentik

Esta parte es la que más se olvida, y la que hace que el ingreso rebote.

En el proveedor de identidad hay que:

1. **Crear la aplicación OIDC** con el `AUTHENTIK_CLIENT_ID` y el secreto del
   paso 1.
2. **Configurar la URL de retorno** exactamente como está en
   `AUTHENTIK_REDIRECT_URI`, que por omisión es
   `https://<DOMINIO>/api/v1/auth/callback`.
3. **Pedir el scope de grupos.** El claim de grupos es de donde salen el rol y el
   consultorio. Si no está, el ingreso falla con un mensaje genérico.
4. **Crear tres equipos** con los nombres exactos:
   - `Recepcion de ClinicaDental`
   - `Profesionales de ClinicaDental`
   - `Administradores de ClinicaDental`
5. **Crear el equipo del consultorio**, que se llama `consultorio-<slug>`. Por
   ejemplo, `consultorio-casa-central`.
6. **Agregar a cada persona** al equipo de su rol y al equipo del consultorio.

Si los nombres no coinciden exactamente con los de `config/authentik.php`, el rol
no se resuelve y la cuenta entra sin consultorio.

### 8. El primer ingreso

Abra `https://<DOMINIO>` y apriete **Ingresar**.

Si rebota:

| Síntoma | Qué revisar |
|---|---|
| Vuelve al ingreso sin error | El equipo `consultorio-<slug>` no existe o el nombre no coincide |
| "La cuenta no está registrada en ningún consultorio" | El claim de grupos no viene, o el prefijo `consultorio-` no es el del grupo |
| Error del proveedor de identidad | La URL de retorno no coincide, o el cliente está mal |

### 9. Cargar los datos de ejemplo (opcional)

**Solo en desarrollo.** En producción esto mete pacientes ficticios en la base
real.

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan migrate:fresh --seed
```

Los datos personales del seeder son **inventados** y los RUT que usa
(`0.000.000-1`, `-2` y `-3`) **no existen en Chile**. Ver
[06](06-PRIMER-PACIENTE.md).

---

## En desarrollo

```bash
cd apps/clinica-dental
cp .env.example .env
cp api/.env.example api/.env

# En api/.env, para desarrollo: la API va a estar en tu maquina, no en Docker.
DB_HOST=127.0.0.1

docker compose -f deploy/docker-compose.dev.yml up --build
```

- Interfaz: <http://localhost:3000>
- API: <http://localhost:8000>
- Caddy: <http://localhost:8080>

Los puertos están atados a `127.0.0.1` a propósito: no quedan expuestos a la red
local.

El compose de desarrollo usa la **misma imagen** que producción. La única
diferencia es que `php.ini` se sobrescribe para que opcache valide los archivos
en cada petición. No hay un build de desarrollo separado, porque en los proyectos
anteriores de Hefesto fue justamente el origen de diferencias que aparecían solo
en producción.

### Datos de ejemplo

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan migrate:fresh --seed
```

Carga un consultorio con un administrador, una recepción, dos profesionales, un
catálogo de diez procedimientos con su tarifario, cinco insumos y tres pacientes
con antecedentes (uno sano, uno con alergia anafiláctica al látex, uno con
hipertensión bloqueante).

---

## Correr las pruebas

Las pruebas **no** pueden correr en SQLite:

```bash
# La base de pruebas tiene que ser PostgreSQL. Ver abajo.
docker compose -f deploy/docker-compose.dev.yml exec postgres \
  createdb -U clinica_dental clinica_dental_test

docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan test --testsuite=Aislamiento

docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan test --testsuite=Permisos

docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan test --testsuite=Unit
```

**Por qué PostgreSQL y no SQLite**: el aislamiento entre consultorios está
implementado con Row Level Security, que es una función de PostgreSQL. En SQLite
estas pruebas pasarían sin comprobar nada: un sistema sin aislamiento alguno
también daría verde. `phpunit.xml` fija `DB_CONNECTION=pgsql` y
`DB_DATABASE=clinica_dental_test` por eso, y hay un comentario en el archivo que
lo explica.

---

## Operación diaria

```bash
# Estado
docker compose -f deploy/docker-compose.prod.yml ps

# Registro de la API (NO guarda cuerpos: el nivel es info a proposito)
docker compose -f deploy/docker-compose.prod.yml logs -f api

# Intentos de acceso denegados que no llegaron a la base
docker compose -f deploy/docker-compose.prod.yml exec api \
  tail -f storage/logs/accesos-rechazados.log

# Limpiar caches sin reiniciar
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan optimize:clear
```

### Antes de cada despliegue

```bash
# 1. Respaldo. Ver docs/08.
deploy/respaldos/respaldo.sh

# 2. Desplegar.
docker compose -f deploy/docker-compose.prod.yml up -d --build

# 3. Verificar el aislamiento. SIEMPRE.
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan clinica:verificar-aislamiento
```

---

## Problemas frecuentes

### "La instalación NO tiene el aislamiento activo"

`clinica:verificar-aislamiento` falló. **No siga.** O bien faltan migraciones, o
la conexión está a un usuario que no es el dueño de las tablas. La segunda es
frecuente: si la base se restauró con un `--no-owner` y el usuario actual no es
el dueño, el `FORCE ROW LEVEL SECURITY` no le aplica.

### "Falta el contexto de consultorio"

Una consulta se hizo sin `app.tenant_id` fijado. Casi siempre es una ruta que
toca una tabla protegida y no pasó por `ResolverTenant`: una ruta mal puesta
fuera del grupo `auth`, o un comando de consola.

Es **el comportamiento correcto**: el aislamiento falla cerrado. Ver
[03](03-MULTITENANCY-Y-ROLES.md).

### "Falta el archivo .env" o "APP_KEY vacío"

El contenedor de la API no arranca. Es a propósito: un contenedor sano que
responde mal es peor que uno que no responde, porque el balanceador le manda
tráfico igual.

### El ingreso rebota

Ver la tabla del paso 8. Casi siempre es el equipo de Authentik.

---

→ [05](05-CONFIGURACION.md) para cada variable en detalle.
→ [06](06-PRIMER-PACIENTE.md) para el primer paciente paso a paso.
→ [08](08-RESPALDOS.md) antes de poner esto en producción. Lo último, no lo
primero.