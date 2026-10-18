# 09 — API

Prefijo: **`/api/v1`**. Se aplica en `routes/web.php`, no en el esqueleto, para
que quede a la vista que toda la superficie está versionada en una sola línea.

La consume el BFF de Nuxt (`web/server/api`), que reenvía la cookie de sesión
del navegador. **No hay token en el código del navegador.**

---

## Convenciones

### Nombres

Todo en **español y con guiones bajos**: los valores de los enums y los campos
JSON van en `snake_case` (`ver_cronologia`, `puede_ser_fatal`,
`estado_resultante`).

Cada estado viene con su etiqueta en texto: la interfaz **no traduce** el
estado. Duplicar la tabla de etiquetas en el navegador es como las dos versiones
dejan de coincidir, y un estado mal traducido termina pareciendo una alergia
moderada.

### Éxito

Una **sola forma** para toda la API:

```json
{ "campo": "valor" }
```

Los listados añaden sus propias claves (`datos`, `total`) y los recursos
individuales devuelven `{"<recurso>": {...}}`. No hay un envoltorio `data:`
distinto del nombre del recurso.

### Error

```json
{
  "mensaje": "Alergia activa a látex: hinchazón (gravedad Severa).",
  "errores": { "campo": ["mensaje legible"] }
}
```

`errores` solo aparece en errores de validación (422).

| Código | Cuándo |
|---|---|
| `401` | no hay sesión, o venció |
| `403` | la cuenta está desactivada o no tiene consultorio. **No es un rechazo de permiso** |
| `404` | no existe **o el permiso lo rechazó** |
| `419` | la sesión expiró mientras se guardaba (CSRF) |
| `422` | validación, o una decisión de negocio (alergia, choque de agenda, transición inválida) |
| `500` | algo que no debería pasar |

### Por qué 404 y no 403 en los rechazos de permiso

Un 403 confirma que el recurso existe. En un historial odontológico eso importa
más que en un portal: no se le dice a quien preguntó si el paciente existe en
otro consultorio.

Está configurado en `bootstrap/app.php`, que traduce `AuthorizationException` a
404 para todo lo que cuelgue de `api/v1/*`.

Y hay una consecuencia que hay que asumir: **la interfaz no puede distinguir
"no existe" de "no lo puede ver", y no debe intentarlo.**

---

## Autenticación

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/auth/login` | Redirige al emisor de Authentik (302) |
| `GET` | `/api/v1/auth/callback` | Canjea el código, abre la sesión, redirige |
| `POST` | `/api/v1/auth/logout` | Cierra la sesión (requiere sesión) |
| `GET` | `/api/v1/auth/usuario` | Datos de la persona y sus capacidades (requiere sesión) |

`GET /api/v1/salud` es el chequeo de salud, declarado en `bootstrap/app.php`.
**No necesita sesión** y no toca ninguna tabla protegida: un chequeo que
necesitara `app.tenant_id` fallaría justo cuando se quiere saber si la API
responde.

---

## Permisos por ruta

| Grupo | Middleware | Quién entra |
|---|---|---|
| `panel` | `auth` | los tres roles |
| `agenda`, `citas` | `auth` | los tres roles |
| `pacientes` | `auth` | los tres roles (ficha de identificación) |
| `pacientes/{p}/alergias`, `condiciones` | `auth` | los tres roles **leen**; solo profesional **escribe** |
| `pacientes/{p}/desbloqueos` | `auth` | los tres roles **leen**; solo profesional con ficha **concede** |
| `.../odontograma` | `odontograma.auditado:ver_estado_actual` | los tres roles |
| `.../cronologia` | `odontograma.auditado:ver_cronologia` | profesional y administrador |
| `.../intervenciones` | `odontograma.auditado:registrar_intervencion` | profesional **con ficha** |
| `planes` | `odontograma.auditado:ver_plan` / `editar_plan` | profesional y administrador |
| `administracion/*` | `auth` + `admin` | administrador |

---

## Panel

| Método | Ruta | Qué devuelve |
|---|---|---|
| `GET` | `/api/v1/panel` | Resumen del día con lo que hay que revisar |

Solo **cuenta**. No devuelve nombres junto con las alertas, porque el panel es
una pantalla que puede quedar abierta a la vista en el mostrador.

---

## Agenda

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/agenda` | Agenda de un día, **con los obstáculos ya resueltos por cita** |
| `GET` | `/api/v1/agenda/estados` | Los estados de cita y si ocupan agenda |
| `GET` | `/api/v1/agenda/riesgos/{paciente}` | Alergias, condiciones y procedimientos bloqueados |
| `GET` | `/api/v1/citas` | Citas de un rango, con filtro por paciente o profesional |
| `POST` | `/api/v1/citas` | Agenda una cita |
| `GET` | `/api/v1/citas/{cita}` | Una cita |
| `PATCH` | `/api/v1/citas/{cita}` | Reprograma |
| `DELETE` | `/api/v1/citas/{cita}` | **Anula**, no borra |
| `POST` | `/api/v1/citas/{cita}/desenlace` | Marca atendida o no asistió |

### Lo que hay que saber de las citas

**La duración no viene del cliente.** Sale de `procedimientos.duracion_minutos`.
Un cliente que mandara su propia duración podría solapar citas.

**`DELETE` es anular.** Se exige `motivo`, de mínimo cinco caracteres. La fila
queda con el estado y el motivo.

**Reprogramar anula la vieja y crea la nueva**, dentro de la misma petición. Si
la nueva falla, la vieja vuelve a su estado. Cancelar y volver a agendar sería lo
mismo, pero dejaría dos filas y perdería la cita original.

**Una cita con desenlace no se puede reprogramar ni anular.** El desenlace ya se
escribió y mover la fecha sería reescribir historia clínica.

---

## Pacientes

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/pacientes` | Lista, con `buscar` por nombre o RUT |
| `POST` | `/api/v1/pacientes` | Registra |
| `GET` | `/api/v1/pacientes/{paciente}` | Ficha de identificación |
| `PATCH` | `/api/v1/pacientes/{paciente}` | Actualiza |
| `DELETE` | `/api/v1/pacientes/{paciente}` | **Da de baja**, no borra |

El `GET` incluye `tiene_odontograma`, `citas_totales`, `ultima_atencion` y
`dias_sin_control`. Son datos de agenda, no clínicos.

---

## Alergias, condiciones y desbloqueos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/pacientes/{p}/alergias` | Historial completo: activas y cerradas |
| `POST` | `/api/v1/pacientes/{p}/alergias` | Registra. Solo profesional con ficha |
| `PATCH` | `/api/v1/pacientes/{p}/alergias/{alergia}` | Actualiza |
| `POST` | `/api/v1/pacientes/{p}/alergias/{alergia}/cerrar` | Cierra con motivo |
| `GET` | `/api/v1/escala-alergias` | La escala de severidad y su urgencia |
| `GET` | `/api/v1/pacientes/{p}/condiciones` | Igual, para condiciones |
| `POST` | `/api/v1/pacientes/{p}/condiciones` | Registra |
| `POST` | `/api/v1/pacientes/{p}/condiciones/{condicion}/cerrar` | Cierra con motivo |
| `GET` | `/api/v1/pacientes/{p}/desbloqueos` | Desbloqueos vigentes y cerrados |
| `POST` | `/api/v1/pacientes/{p}/desbloqueos` | Concede. Solo profesional con ficha |
| `DELETE` | `/api/v1/pacientes/{p}/desbloqueos/{desbloqueo}` | **Cierra**, no borra |
| `GET` | `/api/v1/pacientes/{p}/desbloqueos/comprobar` | Por qué no se puede agendar |

### `/comprobar` es la ruta más útil de esta pantalla

Devuelve `puede_agendar`, el motivo del bloqueo y los desbloqueos vigentes. La
interfaz la llama **antes** de intentar agendar, para que el motivo del bloqueo
no sea un error seco después de apretar el botón.

---

## Odontograma

| Método | Ruta | Acción auditada |
|---|---|---|
| `GET` | `/api/v1/pacientes/{p}/odontograma` | `ver_estado_actual` |
| `POST` | `/api/v1/pacientes/{p}/odontograma` | `ver_estado_actual` |
| `GET` | `/api/v1/pacientes/{p}/cronologia/{numero_fdi}` | `ver_cronologia` |
| `GET` | `/api/v1/pacientes/{p}/historial/{odontograma}` | `ver_cronologia` |
| `POST` | `/api/v1/pacientes/{p}/intervenciones` | `registrar_intervencion` |
| `GET` | `/api/v1/pacientes/{p}/odontograma/{odontograma}/transiciones/{numero_fdi}` | `ver_estado_actual` |

`{numero_fdi}` es un número de dos cifras, de `11` a `48`, y la ruta solo acepta
`[0-9]{2}`.

### El mapa trae las notas según el permiso

```json
{ "incluye_notas": false, "piezas": [{ "numero_fdi": 16, "notas": null }] }
```

El campo va en `null` en vez de desaparecer, para que la interfaz no tenga que
adivinar por qué falta.

### `/transiciones` es una ayuda, no el control

Devuelve las salidas válidas desde el estado actual de esa pieza, para que la
interfaz no ofrezca un botón que el servidor va a rechazar.

**El servidor las vuelve a comprobar igual.** La lista nunca es el control.

---

## Planes

| Método | Ruta | Acción auditada |
|---|---|---|
| `GET` | `/api/v1/pacientes/{p}/planes` | `ver_plan` |
| `GET` | `/api/v1/planes/{plan}` | `ver_plan` |
| `POST` | `/api/v1/pacientes/{p}/planes` | `editar_plan` |
| `PATCH` | `/api/v1/planes/{plan}` | `editar_plan` |
| `POST` | `/api/v1/planes/{plan}/estado` | `editar_plan` |
| `POST` | `/api/v1/planes/{plan}/etapas` | `editar_plan` |
| `PATCH` | `/api/v1/etapas-plan/{etapa}/estado` | `editar_plan` |

Un plan nace siempre en `borrador`. Pasarlo a `presentado` es otra acción,
porque presentarle algo al paciente es un hecho distinto de anotarlo.

Mover el estado tiene su propia ruta y su propio permiso: cambiar el texto de una
etapa y aprobar el plan no son lo mismo.

---

## Administración

Todas bajo `auth` **y** `admin`: el middleware es la puerta, la Policy es la
regla.

| Método | Ruta |
|---|---|
| `GET` | `/api/v1/administracion/profesionales/para-agenda` |
| `GET` `POST` | `/api/v1/administracion/profesionales` |
| `GET` | `/api/v1/administracion/profesionales/{profesional}` |
| `PATCH` | `/api/v1/administracion/profesionales/{profesional}` |
| `GET` `POST` | `/api/v1/administracion/procedimientos` |
| `GET` | `/api/v1/administracion/procedimientos/{procedimiento}` |
| `PATCH` | `/api/v1/administracion/procedimientos/{procedimiento}` |
| `GET` `POST` | `/api/v1/administracion/insumos` |
| `PATCH` | `/api/v1/administracion/insumos/{insumo}` |
| `POST` | `/api/v1/administracion/insumos/{insumo}/stock` |
| `GET` `POST` | `/api/v1/administracion/tarifas` |
| `PATCH` | `/api/v1/administracion/tarifas/{tarifa}` |
| `GET` | `/api/v1/administracion/usuarios` |
| `POST` | `/api/v1/administracion/usuarios/{usuario}/profesional` |
| `POST` | `/api/v1/administracion/usuarios/{usuario}/desactivar` |
| `GET` | `/api/v1/administracion/auditoria` |

### `/para-agenda` tiene su propio permiso

La lista de profesionales la necesita **toda** la agenda, no solo la
administración. Abre el nombre y la especialidad, no el correo ni el registro.

### El stock se ajusta, no se edita

`stock_actual` no se cambia con un `PATCH`: tiene su propia ruta con **motivo
obligatorio**. Una resta sin explicación es donde se esconden las diferencias
de inventario.

> El consumo **no** se descuenta solo desde el registro de la intervención. Hoy el
> ajuste es manual. Ver [10](10-PRUEBAS-Y-ESTADO.md).

### `/auditoria` devuelve cuentas, no el detalle

Solo el administrador. Y devuelve **cantidades** por resultado, acción y persona,
no el detalle fila por fila.

El detalle existe en `accesos_odontograma` y existe para una revisión formal. Una
pantalla con el detalle es una pantalla que se puede dejar abierta, y esa
pantalla revela información de salud de pacientes.

---

## Límites de la API

### No hay paginación en la agenda

`/agenda` tiene un tope de 500 citas por rango y un límite de 180 días de
anticipación. No hay paginación.

Para un consultorio chico es suficiente. Si alguna vez no lo fuera, el
`cliente_paginas_por_peticion` es un parámetro que hay que agregar junto con el
cursor, no un `offset`.

### La búsqueda de pacientes no tiene acentos

`buscar` usa `ILIKE` con `%texto%`. "Muñoz" no encuentra "Munoz". Es una
limitación consciente: normalizar acentos en la base rompería los RUT y los
nombres que sí llevan, y el problema real de un consultorio es buscar por RUT, que
no tiene tildes.

### No hay subida de archivos

`file_uploads = Off`. No hay radiografías, ni documentos, ni nada.

### No hay escritura por lote

Todas las rutas de escritura reciben un recurso a la vez.

### Lo que la API no expone

| No está | Por qué |
|---|---|
| Detalle fila por fila de la bitácora | ver más arriba |
| `DELETE` en pacientes, citas, alergias, condiciones, planes, desbloqueos | se usa estado, no borrado |
| `PUT` ni `PATCH` en odontogramas ni piezas | el mapa no se edita: se registra una intervención |
| Cualquier ruta de facturación | no está implementada |

---

## El CSRF y el BFF

Las rutas usan el grupo `web` de Laravel: la cookie de sesión viaja y el CSRF se
valida.

El BFF (`web/server/utils/api.ts`) lee la cookie `XSRF-TOKEN` y la devuelve como
cabecera `X-XSRF-TOKEN`. Sin eso, todas las peticiones que modifican datos
devuelven 419 y el error es muy difícil de entender.

---

→ [03](03-MULTITENANCY-Y-ROLES.md) para quién puede qué.
→ [01](01-ARQUITECTURA.md) para el recorrido de una petición.