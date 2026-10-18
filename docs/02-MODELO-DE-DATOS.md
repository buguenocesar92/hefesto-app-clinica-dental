# 02 — Modelo de datos

PostgreSQL, y no otro motor. El aislamiento entre consultorios está
implementado con Row Level Security, que es una función de PostgreSQL: en otro
motor no existiría, y las pruebas pasarían sin comprobar nada.

Hay **21 migraciones**: 19 tablas y 2 de protección.

---

## Las diecisiete tablas de negocio

Todas llevan `tenant_id`, sin excepción. Es lo que compara la política de RLS
con el contexto de la petición.

### Identidad y estructura del consultorio

| Tabla | Para qué |
|---|---|
| `consultorios` | Los consultorios de la instalación. **Sin RLS**: hay que leerla para saber cuál es el tenant. |
| `usuarios` | Copia de trabajo de la identidad que emite Authentik: rol, ficha de profesional, activo. |
| `profesionales` | Quien atiende. Ficha corta: nombre, especialidad, registro. |

### Catálogos

| Tabla | Para qué |
|---|---|
| `procedimientos` | Catálogo de lo que se hace. **De aquí sale la duración de la cita.** |
| `tarifas` | Precio por procedimiento, con vigencia por fecha. |
| `insumos` | Material que se consume, con stock y mínimo. |

### Pacientes y sus antecedentes

| Tabla | Para qué |
|---|---|
| `pacientes` | Ficha de **identificación**: nombre, RUT, contacto, previsión. No es historia clínica. |
| `alergias` | Sustancia, reacción, severidad. La severidad la pone el profesional. |
| `condiciones` | Estado de salud general. `bloquea_atencion` la marca el profesional. |
| `desbloqueos_alergia` | La autorización firmada que levanta un bloqueo. |

### El odontograma

| Tabla | Para qué |
|---|---|
| `odontogramas` | Un mapa por paciente. Único por paciente. |
| `piezas` | Las 32 piezas permanentes, con su estado y la fecha desde la que lo tienen. |
| `intervenciones_pieza` | **La historia clínica.** Un hecho fechado, firmado por un profesional. |

### Planes y agenda

| Tabla | Para qué |
|---|---|
| `planes_tratamiento` | Qué se propone hacer, con estados desde `borrador` hasta `completado`. |
| `etapas_plan` | Las etapas, en orden, con su pieza y su procedimiento. |
| `citas` | La agenda. |
| `bloqueos_agenda` | Tramos en que un profesional no atiende. |
| `accesos_odontograma` | Quién abrió el historial odontológico y con qué resultado. |

### Exentas de RLS

| Tabla | Por qué |
|---|---|
| `consultorios` | Se consulta en el ingreso, antes de que exista `app.tenant_id`. |
| `sessions` | Se consulta antes de saber el tenant. Con RLS, nadie podría iniciar sesión. |
| `cache`, `cache_locks` | No son datos del consultorio. |
| `migrations` | Las crea Laravel. |

---

## El odontograma es historia clínica inmutable

Esta es la decisión estructural más importante de la aplicación.

**El estado de una pieza no se edita.** Se cambia registrando una
intervención, que es un hecho con fecha, con profesional y con descripción.

Por qué:

- Una historia clínica que se puede reescribir no es una historia clínica.
- El mapa dental responde a "cómo está la boca de esta persona", y eso se
  construye con lo que se le hizo, no con una celda que alguien editó.
- La odontología tiene una máquina de estados real: una pieza extraida no vuelve
  a un estado natural. Sin esa restricción, un `UPDATE` podría dejar una pieza
  extraida y sana al mismo tiempo.

### La máquina de estados

`App\Services\Odontograma\MaquinaDeEstadosPieza` declara catorce estados:

| Grupo | Estados |
|---|---|
| Sanas o con alteración | `sano`, `caries`, `obturado`, `corona`, `endodoncia`, `sellante`, `fractura` |
| Fuera de la boca | `ausente`, `extraida` |
| Con indicación de extracción | `por_extraer`, `indicacion_extraccion` |
| Reemplazos | `implante`, `protesis`, `puente` |

Lo que restringe:

1. **`ausente` es terminal.** La pieza nunca estuvo. Si en realidad se extrajo,
   eso es un hecho distinto y hay que registrarlo como extracción, que queda en
   la cronología.
2. **`implante` es terminal en esta versión.** Si fracasa y hay que retirarlo, es
   una intervención nueva. El sistema no modela todavía esa intervención;
   queda anotado en [10](10-PRUEBAS-Y-ESTADO.md).
3. **Desde `extraida` solo se sale a un reemplazo** (implante, prótesis o
   puente). Volver a un estado natural borraría el hecho de la extracción.
4. **Quedarse en el mismo estado está permitido.** Repetir un sellante o rehacer
   una obturación es algo normal.

Lo que **no** restringe: `sano` a `obturado` está permitido. El sistema no
juzga si el diagnóstico de caries estaba bien puesto, porque eso es criterio
clínico del profesional. Una máquina de estados que lo impusiera sería inventar
odontología.

### La inmutabilidad también está en la base

La migración `2026_02_10_000021` pone tres disparadores:

| Disparador | Qué rechaza |
|---|---|
| `fn_cronologia_sin_borrado` | borrar una intervención |
| `fn_piezas_exigen_cronologia` | mover una pieza sin una intervención que lo justifique |
| `fn_piezas_no_se_restaura_tras_extraccion` | devolver a un estado natural una pieza extraída |

Es decir: **la inmutabilidad no depende de que la aplicación se porte bien.**
Si alguien escribiera en `piezas` desde fuera de `ServicioOdontograma::registrar()`,
la base lo rechaza.

### Una corrección se registra, no se borra

Si el odontograma estaba mal, la corrección es una intervención nueva que
explica el cambio. `OdontogramaPolicy::update()` devuelve siempre `false` para
que quede escrito que la ausencia de esa capacidad es una decisión y no un
olvido.

---

## Las fechas que no son la misma fecha

`intervenciones_pieza` tiene **dos** fechas, y no son intercambiables:

| Columna | Qué es | Quién la pone |
|---|---|---|
| `fecha` | Cuándo ocurrió el hecho en la boca del paciente | El profesional, y puede ser pasada |
| `fecha_registro` | Cuándo alguien escribió ese dato acá | El sistema, siempre |

Al migrar un cuaderno de papel, estas dos difieren en años: el hecho ocurrió en
2019 y se escribió en 2026. Por eso son dos columnas y no una.

Lo mismo con `piezas.estado_desde` (desde cuándo tiene ese estado) y
`piezas.ultima_intervencion_fecha` (cuándo se le hizo algo por última vez). Y con
`odontogramas.ultima_intervencion_at`.

---

## La cronología se puede auditar, no editar

La cronología es la proyección del mapa sobre el tiempo: losodontogramas
muestran cómo está la boca **ahora**, las crónologías muestran **qué se le hizo
y cuándo**. Se guardan en tablas distintas a propósito: una es el estado, la otra
es el relato.

Y el relato es el que se audita. Un paciente puede tener el mapa abierto delante
en el mostrador y que la cronología esté cerrada.

---

## Reglas transversales

### `tenant_id` en todas las filas

Ninguna tabla de negocio se escribe sin declarar a qué consultorio pertenece.
El trait `PerteneceAlConsultorio` expone la relación y **nada más**: no hay
ningún método que "ponga" el tenant, porque el contexto lo fija exclusivamente
`ContextoTenant::establecer()`, dentro de una transacción. Un modelo que
escribiera `app.tenant_id` por su cuenta rompería el alcance de transacción y el
worker de colas arrastraría el consultorio equivocado.

### Índices pensados para el patrón real

| Índice | Para qué consulta |
|---|---|
| `citas (tenant_id, profesional_id, fecha_inicio)` | la agenda de un profesional en un rango |
| `citas (tenant_id, paciente_id, fecha_inicio)` | el historial de citas de un paciente |
| `intervenciones_pieza (tenant_id, paciente_id, numero_fdi, fecha)` | la cronología de una pieza |
| `intervenciones_pieza (tenant_id, paciente_id, fecha)` | el historial completo, agrupado por fecha |
| `alergias (tenant_id, paciente_id, activa)` | el bloqueo antes de agendar |
| `desbloqueos_alergia (tenant_id, paciente_id, vigente_hasta)` | los desbloqueos vigentes de un paciente |
| `accesos_odontograma (tenant_id, momento)` | la bitácora del periodo |
| `accesos_odontograma (tenant_id, paciente_id, momento)` | "quién abrió la ficha de este paciente" |

### Lo que NO se borra

Nada que tenga valor de registro se borra. Hay estado para terminarlo:

| En vez de borrar | Se usa |
|---|---|
| El paciente | `activo = false`. Sus citas e intervenciones quedan. |
| La alergia | `fecha_cierre` + `motivo_cierre` |
| La condición | `fecha_cierre` + `motivo_cierre` |
| La intervención | Nada. Hay un disparador que lo rechaza. |
| La pieza | `estado = extraida` o `ausente` |
| El plan | `suspendido` o `rechazado` |
| La etapa | `omitida` |
| La cita | `anulada` + `motivo` |
| El profesional | `activo = false`. Sus intervenciones quedan firmadas. |
| El desbloqueo | `vigente_hasta` en hoy |
| El insumo | `activo = false` |
| La tarifa | `activo = false`. Las citas viejas guardan su duración. |

La razón es una sola y está en todos los casos: **un registro de salud que se
puede borrar es un registro que no va a servir cuando haga falta**.

---

## La tabla de la bitácora

`accesos_odontograma` está en la lista de tablas protegidas, y eso es
deliberado: la bitácora es del consultorio y no de la instalación.

Tres detalles de su diseño:

- **`paciente_id` sin clave foránea.** El registro tiene que sobrevivir a que
  la ficha se cierre.
- **`usuario_id` con `nullOnDelete`.** Si una cuenta desaparece, el registro
  queda con el nombre guardado en el momento.
- **`created_at` y `updated_at` anulados.** No hay "fecha de creación" y
  "fecha de modificación": hay `momento`, y es uno solo.

Y se escribe en una **conexión aparte** (`config/database.php`, clave
`auditoria`), porque la petición que se deniega termina revertida y el registro
del intento tiene que sobrevivir a esa reversa. Con la misma conexión se perdería
justo el registro que más importa.

### Lo que le falta

El modelo `AccesoOdontograma` **no impone** que un registro no se pueda
modificar ni borrar. Hoy la inmutabilidad depende de que nadie escriba en la
tabla, y no del sistema. Está anotado en
[07](07-SEGURIDAD-Y-LEY-19628.md), en la sección "Lo que NO está hecho".

---

## Las piezas y la notación FDI

32 piezas permanentes, de 11 a 48:

- **Primer dígito = cuadrante.** 1 y 2 superiores, 3 y 4 inferiores.
- **Segundo dígito = posición** dentro del cuadrante, del 1 al 8, de fuera hacia
  el centro.

| Cuadrante | Arcada | Lado del paciente |
|---|---|---|
| 1 | Superior | Derecha |
| 2 | Superior | Izquierda |
| 3 | Inferior | Izquierda |
| 4 | Inferior | Derecha |

El mapa se dibuja **al revés** que el paciente, para que coincida con la norma y
con los impresos: arriba a la izquierda de la pantalla está la pieza 18, que es
la superior derecha del paciente.

Las piezas que el paciente no tiene de partida (agenesia, extracción previa a la
migración) se dejan en `sano` y se corrigen con la primera intervención. Ponerlas
en `ausente` automáticamente sería inventar información clínica.

---

## Las claves foráneas y por qué

| Relación | Comportamiento | Razón |
|---|---|---|
| paciente → cita | `restrictOnDelete` | no se puede borrar un paciente con citas |
| paciente → intervención | `cascadeOnDelete` | con el paciente se va su historia |
| profesional → intervención | `restrictOnDelete` | una intervención sin tratante no se puede sostener |
| profesional → cita | `restrictOnDelete` | una cita sin profesional no tiene sentido |
| procedimiento → cita | `restrictOnDelete` | el nombre del odontograma no se puede quedar sin referencia |
| procedimiento → tarifa | `restrictOnDelete` | idem |
| desbloqueo → cita | `nullOnDelete` | la cita sigue; el desbloqueo puede no estar |

La asimetría entre `cascadeOnDelete` en historia clínica y `restrictOnDelete` en
agenda es a propósito: se conserva todo lo que tiene valor de registro, y se
impide borrar lo que dejaría referencias colgando.

---

## El estado de la cita y la agenda

| Estado | Ocupa agenda | Es un hecho |
|---|---|---|
| `agendada` | sí | no |
| `confirmada` | sí | no |
| `atendida` | sí | **sí** |
| `no_asistio` | **no** | **sí** |
| `anulada` | **no** | no |
| `bloqueada` | no | no |

Una cita anulada o en la que el paciente no asistió sigue apareciendo en el
historial del día, pero deja de bloquear el horario. Si un estado muerto siguiera
ocupando, un consultorio llenaría su agenda de huecos que nadie puede usar.

---

## La duración de la cita sale del catálogo

`citas.duracion_minutos` se copia del procedimiento al agendar. Un cliente que
mandara su propia duración podría solapar citas escribiendo en `piezas` lo que
quisiera.

Además, `citas.duracion_minutos` queda **congelada** en la cita. Si el catálogo
cambia la duración de un procedimiento mañana, las citas ya agendadas conservan
la que tenían: el tiempo que se le prometió al paciente no cambia porque alguien
editara un catálogo.

---

→ [03](03-MULTITENANCY-Y-ROLES.md) para el aislamiento y los roles.
→ [09](09-API.md) para las rutas.