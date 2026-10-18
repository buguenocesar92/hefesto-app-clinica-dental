# ClinicaDental

Aplicación para consultorios dentales en Chile. Historial odontológico,
odontograma, agenda, planes de tratamiento y control de alergias, con aislamiento
entre consultorios impuesto por la base de datos.

Software original de **Hefesto Studio**. No es un despliegue de una herramienta de
terceros.

> **Esta aplicación no está lista para atender pacientes reales y nunca se ha
> ejecutado.** No hay PHP, Composer ni Docker en el equipo donde se escribió: las
> migraciones no se corrieron, las pruebas no se corrieron y los contenedores no se
> construyeron.
>
> Lo que sí se comprobó, y lo que falta, está uno por uno en
> **[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md)**.

---

## Empiece por acá

| Si quiere… | Lea |
|---|---|
| Entender qué es esto y qué no hace | [docs/00-INDICE.md](docs/00-INDICE.md) |
| **Lo que hay que leer antes que nada** | [docs/07-SEGURIDAD-Y-LEY-19628.md](docs/07-SEGURIDAD-Y-LEY-19628.md) |
| Instalarla de cero | [docs/04-INSTALACION.md](docs/04-INSTALACION.md) |
| Saber qué se probó de verdad | [docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md) |

---

## Qué hace

- **Odontograma** con notación FDI y una máquina de estados por pieza. La
  cronología de las piezas es inmutable: no se borra ni se restaura nada.
- **Agenda** con bloqueo previo a la disponibilidad cuando hay una alergia
  activa, porque es el único bloqueo que no se resuelve cambiando de hora.
- **Alergias y condiciones** con desbloqueo firmado por un profesional con ficha,
  y con el motivo escrito.
- **Planes de tratamiento** por piezas, con su presupuesto y su estado.
- **Bitácora de accesos** a los odontogramas.
- **Aislamiento entre consultorios** con Row Level Security de PostgreSQL.

### Lo que NO hace

- No es un sistema de diagnóstico ni recetario.
- No envía datos de pacientes a ningún servicio externo.
- No borra: todo se desactiva o se cierra con un motivo.

---

## Las tres decisiones que explican el resto

**1. El aislamiento lo impone la base, no la aplicación.** PostgreSQL con RLS por
`tenant_id`, forzada. Una consulta sin consultorio fijado falla, no devuelve cero
filas. Ver [docs/03](docs/03-MULTITENANCY-Y-ROLES.md).

**2. Poder agendar no es poder leer una historia clínica.** Recepción agenda y ve
el mapa dental, porque si no lo viera el sistema sería peor que una hoja de
papel. No ve la cronología de las piezas ni los planes.

**3. Una alergia sin tratar bloquea, y solo se levanta firmado.** El bloqueo se
comprueba antes que el horario. Ver [docs/07](docs/07-SEGURIDAD-Y-LEY-19628.md).

---

## Estructura

```
apps/clinica-dental/
|-- api/        Laravel 13: policies, migraciones, RLS, auditoría, pruebas
|-- web/        Nuxt con render en el servidor, y el BFF que reenvía la cookie
|-- deploy/     Dockerfiles, compose de desarrollo y producción, Caddy, respaldos
|-- docs/       once documentos numerados, del 00 al 10
|-- herramientas/   dos scripts que no dependen de PHP ni de Node
|-- .env.example    variables de la composición
`-- .gitignore
```

| Carpeta | Archivos |
|---|---|
| `api/` | 128 |
| `web/` | 28 |
| `deploy/` | 11 |
| `docs/` | 11 |
| `herramientas/` | 2 |
| raíz | 3 |

---

## Puesta en marcha

El procedimiento completo, con cada comando y qué hacer cuando algo falla, está
en [docs/04-INSTALACION.md](docs/04-INSTALACION.md). El resumen:

```bash
# 1. Variables de la composición
cp .env.example .env

# 2. Variables de la API
cp api/.env.example api/.env
cp web/.env.example web/.env

# 3. Desarrollo: PostgreSQL, Redis, API, interfaz y Caddy
docker compose -f deploy/docker-compose.dev.yml up -d

# 4. Esquema de la base
docker compose -f deploy/docker-compose.dev.yml exec api php artisan migrate

# 5. Primer consultorio y su administrador
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan clinica-dental:crear-consultorio

# 6. Comprobar que el aislamiento entre consultorios funciona
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan clinica:verificar-aislamiento
```

En desarrollo los puertos se publican solo en `127.0.0.1`: `5432`, `6379`, `8000`,
`3000` y `8080`.

**En producción se invierte: solo Caddy expone un puerto.** Ni PostgreSQL, ni
Redis, ni la API, ni la interfaz se alcanzan desde afuera.

```bash
docker compose -f deploy/docker-compose.prod.yml up -d
```

---

## Pruebas

```bash
# Aislamiento entre consultorios (necesita PostgreSQL)
php artisan test --testsuite=Aislamiento

# Permisos por rol y bloqueo por alergia
php artisan test --testsuite=Permisos
```

La conexión de pruebas está fijada en `pgsql` a propósito. Sobre SQLite estas
pruebas pasarían sin comprobar nada, porque SQLite no tiene Row Level Security que
lo niegue. Ver [docs/10](docs/10-PRUEBAS-Y-ESTADO.md).

---

## Respaldos

```bash
deploy/respaldos/respaldo.sh
deploy/respaldos/restauracion.sh --archivo <ruta>
```

El respaldo escribe un manifiesto con la suma SHA-256 de cada volcado, y solo se
marca completo al final. La restauración valida el manifiesto antes de tocar
nada, rechaza un destino ocupado salvo que se pase `--force`, nunca ejecuta un
`DROP` y deja el servicio detenido.

**Ninguno de los dos scripts se ha ejecutado nunca.** El procedimiento está en
[docs/08-RESPALDOS.md](docs/08-RESPALDOS.md).

---

## Seguridad y Ley 19.628

La historia clínica y las alergias son **datos sensibles de salud**. Antes de
cargar el primer paciente real hay que resolver lo que el software no puede
resolver: acuerdo de tratamiento de datos, responsable designado, contratos con
los profesionales, protocolos de incidentes, capacitación y control físico del
acceso.

La lista completa de lo que falta está en
[docs/07-SEGURIDAD-Y-LEY-19628.md](docs/07-SEGURIDAD-Y-LEY-19628.md).

---

## Documentación

| | Documento |
|---|---|
| 00 | [Índice](docs/00-INDICE.md) |
| 01 | [Arquitectura](docs/01-ARQUITECTURA.md) |
| 02 | [Modelo de datos](docs/02-MODELO-DE-DATOS.md) |
| 03 | [Multi-tenancy y roles](docs/03-MULTITENANCY-Y-ROLES.md) |
| 04 | [Instalación](docs/04-INSTALACION.md) |
| 05 | [Configuración](docs/05-CONFIGURACION.md) |
| 06 | [Primer paciente](docs/06-PRIMER-PACIENTE.md) |
| 07 | [Seguridad y Ley 19.628](docs/07-SEGURIDAD-Y-LEY-19628.md) |
| 08 | [Respaldos](docs/08-RESPALDOS.md) |
| 09 | [API](docs/09-API.md) |
| 10 | [Pruebas y estado](docs/10-PRUEBAS-Y-ESTADO.md) |

---

## Verificación sin PHP ni Docker

Dos scripts que solo necesitan Python:

```bash
python herramientas/verificar_caracteres.py
python herramientas/verificar_palabras_pegadas.py
```

El primero recorre **todos** los caracteres no ASCII de todos los archivos de
texto y pregunta uno por uno cuál es su nombre Unicode. No se limita a chino y
cirílico: si mañana aparece un alfabeto que nadie pensó, también lo atrapa.

```
Pasada 1 (alfabetos conocidos): sin hallazgos.
Pasada 2 (todos los caracteres): sin hallazgos.
```

**Resultado: limpio, sin hallazgos.**

El segundo busca dos palabras latinas que quedaron pegadas, del tipo
`laaplicacion`. Es un detector, no una puerta: marca y deja la corrección a quien
lee. Marca cincuenta y dos líneas, y las cincuenta y dos son CamelCase legítimo
de nombres de clase escritos en prosa: `Ver CitaPolicy`, `ClínicaDental`.
Ninguna es una palabra pegada real.