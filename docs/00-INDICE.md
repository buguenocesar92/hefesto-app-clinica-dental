# 00 — Índice

Estos son los once documentos de ClinicaDental. Están en el orden en que se
leen cuando alguien se hace cargo del proyecto por primera vez.

**Si no va a leer nada más, lea el [07](07-SEGURIDAD-Y-LEY-19628.md).** En esa
aplicación la historia clínica y las alergias son datos sensibles de salud bajo
la Ley 19.628, y el documento dice qué hace el software y qué NO hace.

---

## Los documentos

| | Documento | Para qué sirve |
|---|---|---|
| 01 | [Arquitectura](01-ARQUITECTURA.md) | Qué hay en cada carpeta, qué corre en cada contenedor y cómo es el recorrido de una petición. |
| 02 | [Modelo de datos](02-MODELO-DE-DATOS.md) | Las diecisiete tablas, sus reglas y por qué el odontograma es inmutable. |
| 03 | [Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md) | **El documento que explica todos los demás.** El aislamiento entre consultorios y los tres roles. |
| 04 | [Instalación](04-INSTALACION.md) | De cero a la primera persona dentro del sistema. |
| 05 | [Configuración](05-CONFIGURACION.md) | Cada variable y por qué existe. Incluye el mapa de equipos de Authentik. |
| 06 | [Primer paciente](06-PRIMER-PACIENTE.md) | La sesión completa, paso a paso, con los tres roles. |
| 07 | [Seguridad y Ley 19.628](07-SEGURIDAD-Y-LEY-19628.md) | **Los controles, lo que falta y el aviso.** El más importante de esta aplicación. |
| 08 | [Respaldos](08-RESPALDOS.md) | Cómo se respalda, cómo se restaura y por qué un respaldo no probado no es un respaldo. |
| 09 | [API](09-API.md) | Las rutas, las convenciones y los límites de la API. |
| 10 | [Pruebas y estado](10-PRUEBAS-Y-ESTADO.md) | **Qué se probó y qué NO.** Empieza por acá antes de opinar sobre el código. |

---

## Las tres decisiones que explican el resto

Si algo de la aplicación no se entiende, el motivo casi siempre es una de estas
tres.

### 1. El aislamiento lo impone la base, no la aplicación

PostgreSQL con Row Level Security por `tenant_id`, forzada. Una consulta sin
consultorio fijado **falla**, no devuelve cero filas. El error de la aplicación
deja de existir en la capa donde puede corregirse.

→ [03](03-MULTITENANCY-Y-ROLES.md)

### 2. Poder agendar no es lo mismo que poder leer una historia clínica

Recepción agenda y ve el mapa dental, porque si no lo viera el sistema sería peor
que una hoja de papel. Pero **no** ve la cronología de las piezas ni los planes
de tratamiento. No hay ninguna bandera que se le pueda poner.

→ [03](03-MULTITENANCY-Y-ROLES.md)

### 3. Una alergia sin tratar se puede bloquear, y solo se levanta firmado

Agendar sobre una alergia activa está bloqueado, y el bloqueo se comprueba antes
que la disponibilidad del horario, porque es el único que no se resuelve
cambiando de hora. Solo un profesional con ficha lo levanta, con un motivo
escrito que queda en la historia del paciente.

→ [07](07-SEGURIDAD-Y-LEY-19628.md)

---

## Aviso que hay que leer antes que nada

> **Esta aplicación NO está lista para atender pacientes reales.**
>
> Manejar datos clínicos exige un conjunto de controles que no se puede
> implementar solo con software: acuerdos de tratamiento de datos con los
> pacientes, designación de un responsable, contratos con los profesionales,
> protocolos de respuesta ante incidentes, capacitación, control físico del
> acceso a los equipos, y la cooperación del registro de salud que le
> corresponde a la institución.
>
> Además, **esta aplicación no se ha ejecutado nunca.** En el equipo donde se
> escribió no hay PHP, ni Composer, ni Docker. No se corrieron las pruebas, ni
> las migraciones, ni una construcción de la imagen. Lo que sí se ejecutó está
> listado, uno por uno, en
> **[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md)**.

---

## Mapa de la aplicación

```
apps/clinica-dental/
|-- api/        Laravel 13: policies, migraciones, RLS, auditoría, pruebas
|-- web/        Nuxt 4 con render en el servidor, y el BFF que reenvía la cookie
|-- deploy/     Dockerfiles, compose de desarrollo y producción, Caddy, respaldos
|-- docs/       estos once documentos
|-- herramientas/  scripts que no dependen de PHP ni de Node
|-- README.md
|-- .env.example        variables de la composición
`-- .gitignore
```