# 03 — Multi-tenancy y roles

Este es el documento que explica el resto. Si algo no se entiende, hay que
volver acá.

---

# Parte 1: Aislamiento entre consultorios

## El patrón: columna `tenant_id` más Row Level Security

**Mismo patrón y misma justificación que en el Portal de Autoservicio de
Hefesto** (`apps/portal-autoservicio/docs/03-MULTITENANCY.md`). No se redecide
acá: se reutiliza. La única diferencia de nombre es que aquí el tenant es un
consultorio y la variable de sesión es `app.tenant_id`.

## Por qué NO un esquema por consultorio

La alternativa (un esquema de base por consultorio) se descartó en el portal, y
la razón sigue valiendo:

| | Columna + RLS | Esquema por consultorio |
|---|---|---|
| Consulta que olvida el filtro | PostgreSQL devuelve cero filas ajenas | Se filtran datos de otro consultorio |
| Índices y planes | Se comparten | Se duplican por consultorio; el planificador se sobredimensiona |
| Respaldos | Uno | Uno por consultorio |
| Alta de un consultorio | Una fila | Crear esquema, dueños, permisos, extensiones |
| Conexiones | Una | Una por consultorio |

El argumento que decide: **con RLS, un error de la aplicación no es una
filtración de datos de salud; es un error.** El fallo deja de existir en la capa
donde puede corregirse.

## Cómo está implementado

### 1. Una función que falla cerrado

```sql
CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
LANGUAGE plpgsql STABLE AS $$
DECLARE valor text;
BEGIN
    valor := current_setting('app.tenant_id', true);

    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION 'Falta el contexto de consultorio (app.tenant_id).';
    END IF;

    RETURN valor::uuid;
END;
$$;
```

Que lance excepción es lo importante. Si devolviera `NULL`, una consulta sin
contexto devolvería "cero filas y ya", que parece un resultado válido. Con la
excepción, **la ausencia de contexto es un error, no una condición.**

### 2. Una política por tabla

```sql
CREATE POLICY politica_aislamiento_tenant ON pacientes
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

`USING` filtra la lectura, la modificación y el borrado. `WITH CHECK` impide
**escribir** una fila declarando un consultorio que no es el del contexto. Sin
`WITH CHECK`, se podrían crear filas ajenas.

La política es por `tenant_id`, no por id de paciente: la columna es común a
todas las tablas y es la que existe en cada una.

### 3. RLS FORZADO en cada tabla

```sql
ALTER TABLE pacientes ENABLE ROW LEVEL SECURITY;
ALTER TABLE pacientes FORCE ROW LEVEL SECURITY;
```

El `FORCE` no es un detalle menor. **Sin él, las políticas no le aplican al
dueño de la tabla**, y en esta instalación el dueño es el mismo usuario con el
que se conecta Laravel (el que migra). El RLS de adorno no protege nada.

### 4. El contexto viaja con alcance de transacción

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $consultorioId]);
```

El `true` final es lo crítico: el valor desaparece al confirmar o revertir la
transacción. Con un `SET` normal (ámbito de sesión) sobreviviría, y en el worker
de colas, que reutiliza la misma conexión para trabajos de consultorios
distintos, una tarea leería datos de otra clínica. Ese es exactamente el fallo
que este diseño evita.

`ContextoTenant::establecer()` **exige** estar dentro de una transacción y lanza
excepción si no lo está: es la única vía para fijar el contexto.

### 5. Un único punto de entrada

`App\Http\Middleware\ResolverTenant` envuelve toda petición autenticada:

```
petición
  -> ResolverTenant  (abre transacción, fija consultorio)
     -> auth         (confirma la sesión)
        -> ruta      (controlador -> Policy -> datos)
```

Las peticiones sin sesión (ingreso, callback, salud) pasan sin transacción. Si
esas rutas tocaran una tabla protegida, `app_tenant_id()` lanzaría excepción y la
petición fallaría. El aislamiento falla cerrado, nunca abierto.

Las peticiones de una cuenta desactivada se cortan ahí, **antes de tocar la base
de datos**, con un 403 y un mensaje que dice qué hacer.

### 6. Las tablas exentas

| Tabla | Por qué no tiene RLS |
|---|---|
| `consultorios` | hay que leerla para saber cuál es el tenant |
| `sessions` | se consulta antes de saber el tenant; con RLS, nadie podría iniciar sesión |
| `cache`, `cache_locks` | no son datos del consultorio |
| `migrations` | las crea Laravel |

`consultorios` está exenta **a propósito y de forma controlada**: es el único
punto por el que se puede crear un consultorio, y por eso es un comando de
consola (`clinica-dental:crear-consultorio`) y no un endpoint. Ver [04](04-INSTALACION.md).

#### Qué lee realmente el selector de ingreso

Antes del ingreso todavía no hay `app.tenant_id`, y por eso `consultorios` es la
excepción controlada del patrón. Conviene decir exactamente qué hace el código,
porque hay una vista que sugiere otra cosa:

- El selector `App\Http\Middleware\ResolverTenant::consultoriosParaLogin()` consulta
  la **tabla** `consultorios`, no una vista. Usa
  `select('id', 'slug', 'nombre', 'nombre_corto', 'activo')` sobre las filas
  activas, ordenadas por nombre.
- Esas **cinco columnas** son todo lo que se proyecta: son columnas de
  identificación. La tabla además guarda `telefono` y `correo`, y **ninguno de los
  dos se pide en esa consulta**. Tampoco hay datos de pacientes ni de fichas
  clínicas en `consultorios`: eso vive en tablas con RLS.
- La vista `vw_consultorios_publicos` **existe** (migración
  `2026_02_10_000001_crear_tabla_consultorios.php`, que expone `slug`,
  `nombre_mostrado` y `activo` de los consultorios activos) y **queda sin uso**: el
  código de ejecución nunca la consulta. No se eliminó, porque sigue sirviendo como
  defensa en profundidad y como respaldo para cuando se decida activar la
  protección.
- **Pendiente, no comprobado:** activar la vista exige revisar qué espera la
  interfaz de `nombre_corto`, porque la vista no trae esa columna. No se ha podido
  comprobar contra una base de datos porque no había una disponible, así que la
  decisión sigue abierta.

## Verificación

```bash
php artisan clinica:verificar-aislamiento
```

Comprueba, sin pasar por la aplicación, que las diecisiete tablas tienen RLS
forzado, que la política existe en cada una, que un consultorio no ve filas de
otro (ni por clave primaria), que no se puede escribir declarando otro
consultorio, y que una consulta sin contexto **falla**.

Escribe filas temporales y las borra al final. **No debe correrse contra una base
con datos reales de pacientes.**

En las pruebas, `tests/Feature/Aislamiento/` hace lo mismo de forma automatizada.

---

# Parte 2: Roles dentro del consultorio

## Los tres roles

| | recepción | profesional | administrador |
|---|---|---|---|
| Ver y mover la agenda | sí | sí | sí |
| Ver pacientes (identificación) | sí | sí | sí |
| Registrar pacientes | sí | sí | sí |
| **Ver alergias y condiciones activas** | **sí** | sí | sí |
| **Ver el mapa dental (estado actual)** | **sí** | sí | sí |
| **Ver la cronología de las piezas** | **no** | sí | sí |
| **Ver y editar planes de tratamiento** | **no** | sí | sí |
| **Registrar una intervención** | **no** | sí, con ficha | **no** |
| **Registrar una alergia** | **no** | sí, con ficha | **no** |
| **Cerrar una alergia** | **no** | sí, con ficha | **no** |
| **Conceder un desbloqueo** | **no** | sí, con ficha | **no** |
| Registrar el desenlace de una cita | **no** | sí | sí |
| Anular una cita | sí | sí | sí |
| Administrar profesionales, insumos, tarifas, usuarios | no | no | sí |
| Ver la bitácora de accesos | no | no | sí |

## La distinción que define el producto

**"Puede agendar" y "puede leer la historia clínica" no son lo mismo.**

Recepción vive en el mostrador: ve nombres, teléfonos, Finanzas y la agenda. No
ve la cronología de las piezas ni los planes de tratamiento. Y no es una
casualidad: que el paciente tenga cita con ella no cambia nada. No existe
ninguna bandera en la base ni en la configuración que le dé acceso; el permiso
depende del rol.

## Las tres cosas que recepción sí puede ver, y por qué

Esta parte es tan importante como lo que no puede ver.

### El mapa dental

Recepción **sí** ve el estado actual de las 32 piezas. Saber que al paciente le
faltan seis piezas es parte de la conversación del mostrador, y no es
información cronológica.

### Las alergias y condiciones

Recepción **sí** ve las alergias activas, y ve el aviso de bloqueo pegado a cada
cita de la agenda.

> Si recepción no las viera, el sistema sería **PEOR QUE UNA HOJA DE PAPEL**:
> aparentaría que no hay riesgo cuando en realidad lo está ocultando. La hoja al
> menos se mira.

El aviso va **dentro** de la cita y no en un aviso aparte, porque un aviso aparte
se lee una vez y después deja de leerse.

### El desenlace de una cita lo ve, pero no lo escribe

Que un paciente haya sido atendido o no es un hecho clínico, no un dato de
agenda. Se escribe desde el sillón.

## El administrador no lee historias clínicas

Es una decisión de producto, no un descuido:

> La dirección del consultorio no es un cargo clínico. El acceso a datos de
> salud se justifica por la atención al paciente, no por la jerarquía dentro de
> la institución.

Un administrador que además atiende pacientes tiene un usuario con rol
`profesional` y su ficha de profesional. Con esa ficha entra; sin ella, no.

Y el administrador que sí puede ver la cronología **no** puede registrar una
intervención, ni cerrar una alergia, ni conceder un desbloqueo. Ver la
historia y escribir en ella son dos capacidades distintas, y la segunda exige
firma.

## Y sí puede ver la bitácora

Eso parece una contradicción y no lo es. La bitácora dice **quién abrió el
historial de quién**: es información sobre las personas de la institución tanto
como sobre los pacientes. Si la pudiera leer un profesional, dejaría de ser un
control y pasaría a ser un instrumento para vigilar al personal, que es
exactamente para lo que no debe servir.

El detalle fila por fila **no** se expone por la API. Existe para una revisión
formal, que no es una pantalla de uso diario.

## Por qué una intervención exige ficha de profesional

`CapacidadesDeUsuario::puedeRegistrarIntervencion()` exige **dos** cosas: rol
`profesional` y `profesional_id` no nulo.

La razón es que la intervención queda firmada por quien la hizo. Una
intervención sin tratante es un hecho que no se puede sostener dentro de una
auditoría. Lo mismo vale para cerrar una alergia y para conceder un
desbloqueo.

## Por qué recepción puede anular una cita y el profesional no

Anular es una decisión de **agenda**: pasa todos los días, y obligar a que llame
a un profesional para dejar de ver una cita que ya no va a ocurrir hace que la
gente aprenda a mentirle al sistema.

Lo que no se permite es dejarlo en un estado que confunda: el motivo de
anulación es obligatorio.

Registrar el desenlace es distinto: es un hecho clínico, y se escribe desde el
sillón.

## De dónde sale el rol

De los **equipos de Authentik**, no de la base:

- `Recepcion de ClinicaDental` → recepción
- `Profesionales de ClinicaDental` → profesional
- `Administradores de ClinicaDental` → administrador
- `consultorio-<slug>` → el consultorio (el `tenant_id`)

El rol se resuelve **en cada ingreso**. Si en Authentik le quitan el equipo a
alguien, en ClinicaDental pierde el acceso en la sesión siguiente. La
alternativa (guardar el rol para siempre en la base) deja ventanas de acceso que
nadie cerraba.

`usuarios.rol` es una **copia de trabajo**, no la fuente de verdad. La fuente es
Authentik. Ver [05](05-CONFIGURACION.md) para el mapa completo.

## Una sesión sin consultorio no entra

Si la persona no pertenece a ningún equipo `consultorio-<slug>` de un consultorio
existente, `ProveedorOidcAuthentik` falla y el ingreso se corta con un mensaje
genérico. No hay cuenta sin consultorio: sin tenant no hay nada que ver.

## Por qué el bloqueo va antes que la disponibilidad

Este es el orden en `ServicioAgenda::agendar()` y no es arbitrario:

1. **Alergias y condiciones.** Es el único bloqueo que no se resuelve buscando
   otro horario. Si el sistema dijera primero "ese horario no sirve", recepción
   probaría con otro y nunca se enteraría de que el problema era la alergia.
2. **Disponibilidad.** Un choque de agenda se resuelve cambiando la hora.
3. **Escritura.** Y el índice único de la base vuelve a comprobar el solapamiento:
   si dos recepcionistas guardan a la vez, una de las dos falla y no se pierde
   ninguna cita.

## La bitácora: sin excepciones

Todo acceso al historial odontológico queda anotado en `accesos_odontograma`,
**permitido o denegado**, con quién, cuándo, desde dónde y por qué ruta.

Por qué los denegados también: un volumen alto de intentos denegados es la señal
de que alguien está probando el sistema con el rol equivocado. Sin ese registro,
el sistema no puede ni notarlo.

Se escribe desde un **middleware**, no desde el controlador. Porque el registro
denegado tiene que existir aunque la acción nunca llegue al controlador: si el
permiso se comprueba dentro del controlador y la excepción corta antes de
escribir nada, el intento denegado desaparece.

## Dónde se aplica cada permiso

| Capa | Qué hace |
|---|---|
| `routes/web.php` | declara qué recurso se toca y con qué middleware |
| Middleware `admin` | corta las rutas de administración para quien no administra |
| Middleware `odontograma.auditado` | anota el intento y corta con 404 |
| `App\Policies\*` | **permiso fino**: quién puede ver, crear, editar, cerrar |
| `App\Services\Permisos\CapacidadesDeUsuario` | responde las preguntas de capacidad |
| PostgreSQL | limita qué filas existen para quién consulta |
| Interfaz (Nuxt) | esconde botones según lo que dice la API |

El orden importa: **la base filtra filas, la Policy decide acciones, la
interfaz solo muestra**. Que un botón no aparezca no impide que alguien llame a
la API, y por eso cada ruta vuelve a preguntar al servidor. Ver
[09](09-API.md).

## Las Policies que devuelven `false` siempre

Cinco Policies tienen métodos que devuelven `false` sin importar quién pregunta,
y **ningún controlador los llama**:

| Policy | Método | Por qué |
|---|---|---|
| `OdontogramaPolicy` | `update`, `delete` | el mapa no se edita: se registra una intervención |
| `PlanTratamientoPolicy` | `delete` | se suspende o se rechaza |
| `AlergiaPolicy` | `delete` | se cierra con fecha y motivo |
| `CondicionPolicy` | `delete` | se cierra con fecha y motivo |
| `DesbloqueoPolicy` | `update`, `delete` | se cierra; el registro original permanece |
| `PacientePolicy` | `delete` | se da de baja con `activo = false` |
| `AccesoOdontogramaPolicy` | `view` | el detalle de la bitácora no se expone por la API |

Están escritos para que quede **explícito** que la ausencia es una decisión de
diseño y no un olvido. Es la diferencia entre "no se implementó" y "no se debe".

---

→ [04](04-INSTALACION.md) para poner esto en pie.
→ [07](07-SEGURIDAD-Y-LEY-19628.md) para lo que esto no alcanza.

---

## Los dos roles de PostgreSQL

La base de esta aplicación corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre consultorios funcione en vez
de quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `clinica_dental_admin` | `POSTGRES_USER` | Crear la base `clinica_dental` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `clinica_dental` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

El rol de aplicación **no** lleva sufijo `_app`: se llama `clinica_dental`, igual
que la base.

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar: un
consultorio habría visto las fichas de los pacientes y los planes de tratamiento
de los demás, sin error y sin aviso. Con datos de salud, eso no puede pasar.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE clinica_dental OWNER TO clinica_dental;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

### Cómo se comprueba

```
php artisan clinica:verificar-aislamiento
php artisan clinica:verificar-aislamiento --consultorio
```

El prefijo del comando es `clinica:`, no `clinica-dental:`, y es el mismo que
usan `clinica-salud` y `veterinaria`. Son aplicaciones distintas con bases
distintas, así que el nombre repetido no es un conflicto, pero sí es una fuente
de confusión al leer la documentación de una con la de otra.

La opción `--consultorio` recibe el identificador de un consultorio existente
para usarlo como contexto A; si no se indica, se crea una fila temporal.

**Una diferencia que hay que conocer:** este comando **no** consulta `pg_roles`
para revisar los atributos del rol. Lo que hace es comprobar que las tablas
protegidas tengan RLS activada y forzada, y hacer una **lectura cruzada**: el
consultorio A no debe poder ver el paciente del consultorio B, ni siquiera
preguntando por su identificador.

Esa lectura cruzada detecta un rol equivocado de forma indirecta: si la conexión
fuera superusuario, A vería al paciente de B y el comando fallaría. Aun así, la
comprobación directa de `pg_roles` no está automatizada y conviene hacerla a
mano.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).