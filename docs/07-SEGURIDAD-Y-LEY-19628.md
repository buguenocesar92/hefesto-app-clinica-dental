# 07 — Seguridad y Ley 19.628

**Este es el documento más importante de la aplicación.** En un consultorio
dental, el odontograma, las alergias y las condiciones de salud son **datos
personales sensibles** bajo la Ley 19.628, y el acceso indebido a ellos no es
una falla técnica: es un delito.

---

## Aviso, primero y en serio

> **Esta aplicación NO está lista para atender pacientes reales.**
>
> Manejar datos clínicos exige un conjunto de controles que no se puede
> implementar solo con software: acuerdos de tratamiento de datos con los
> pacientes, designación de un responsable, contratos con los profesionales,
> protocolos de respuesta ante incidentes, capacitación, control físico del
> acceso a los equipos, y la cooperación del registro de salud que le corresponde
> a la institución.
>
> Poner esto en producción sin esas validaciones es un riesgo para el paciente,
> para la clínica cliente y para Hefesto Studio.
>
> **Además, esta aplicación no se ha ejecutado nunca.** En el equipo donde se
> escribió no hay PHP, ni Composer, ni Docker. No se corrieron las pruebas, ni
> las migraciones, ni una construcción de imagen. Ver
> [10](10-PRUEBAS-Y-ESTADO.md).
>
> Este documento explica qué hace el software. No reemplaza el trabajo jurídico
> ni las garantías técnicas que la normativa exige.

---

## Ley 19.628

Los datos de salud son **datos personales sensibles** bajo la Ley 19.628 sobre
Protección de la Vida Privada. Eso cambia el tratamiento que corresponde:

- El **titular** tiene derecho a saber, a acceder, y a pedir rectificación o
  supresión de sus datos.
- La **finalidad** tiene que ser determinada y declarada. La historia clínica
  existe para atender pacientes; no para otros usos.
- El acceso indebido a datos de salud no es una falla técnica: es un delito.

> ### Aviso de verificación jurídica
>
> Esta sección fue escrita el 2026-10-17 a partir de la Ley 19.628. **No se pudo
> verificar en este entorno** el estado de las reformas posteriores a la distancia
> (en particular la creación de una Agencia de Protección de Datos Personales y
> los cambios asociados): el sitio del BCN carga con JavaScript y no se pudo
> leer.
>
> Antes de cualquier despliegue real, **un abogado debe revisar esta sección
> completa** y redactar los textos de consentimiento y aviso de privacidad que
> usará la clínica. Queda como trabajo pendiente, no como algo resuelto.
>
> **Lo mismo vale para el plazo de conservación**, que es el punto donde más
> fácil es inventar un dato. Véase la sección de conservación, más abajo.

---

## Qué hace el software

### 1. Aislamiento entre consultorios

Row Level Security de PostgreSQL, con política por `tenant_id` y RLS **forzada**
en las diecisiete tablas de negocio.

Una consulta sin consultorio en contexto **falla**, no devuelve cero filas: el
aislamiento falla cerrado.

```bash
php artisan clinica:verificar-aislamiento
```

→ [03](03-MULTITENANCY-Y-ROLES.md)

### 2. Separación de roles dentro del consultorio

| | recepción | profesional | administrador |
|---|---|---|---|
| Ficha de identificación | sí | sí | sí |
| Mapa dental (estado actual) | sí | sí | sí |
| Alergias y condiciones | sí | sí | sí |
| **Cronología de las piezas** | **no** | sí | sí |
| **Planes de tratamiento** | **no** | sí | sí |
| **Registrar intervención** | **no** | sí, con ficha | **no** |
| **Cerrar alergia / desbloquear** | **no** | sí, con ficha | **no** |

La bitácora está aparte: el administrador lee el **cuenta** de quién abrió el
historial, pero no la historia clínica.

→ [03](03-MULTITENANCY-Y-ROLES.md)

### 3. Consentimiento: qué hay y qué no hay

**LO QUE ESTÁ:**

- Un campo `notas_administrativas` en el paciente, corto (mil caracteres),
  deliberadamente limitado para que no se cuelen datos clínicos ahí.
- Un registro de la alergia con **fecha de registro**, **fecha de cierre** y
  **motivo de cierre**, de modo que la decisión queda fechada.

**LO QUE NO ESTÁ:**

- **No hay registro de consentimiento.** No hay tabla de consentimientos, ni
  documento de consentimiento, ni firma, ni revocación.
- **No hay un módulo de aviso de privacidad.**
- **No hay exportación** de datos del titular.
- **No hay rectificación ni supresión** del historial clínico. Se puede editar
  la ficha de identificación; el historial odontológico no se edita por diseño.

> **Esto es una carencia, no una decisión de diseño.** Un sistema que almacena
> datos sensibles de salud necesita un registro de consentimiento y un mecanismo
> para ejercer los derechos del titular, y esta versión **no lo tiene**. Está
> anotado en [10](10-PRUEBAS-Y-ESTADO.md) como pendiente, y hay que resolverlo
> antes de cualquier despliegue real.

Lo único que el sistema pone en manos del consultorio es la **bitácora de
accesos**: quién abrió el historial de qué paciente y cuándo. Ese es el
instrumento para responder al paciente cuando pregunta quién miró su ficha.

### 4. Auditoría de accesos al historial odontológico

**Todo** acceso a información clínica queda registrado: permitido o denegado,
con quién, cuándo, desde qué dirección IP, por qué ruta, con qué método y con
qué respuesta.

| Decisión | Por qué |
|---|---|
| Los intentos **denegados** también se anotan | son la única forma de detectar que alguien prueba el sistema con el rol equivocado |
| Se anota desde un **middleware**, no desde el controlador | el registro denegado tiene que existir aunque la acción nunca llegue al controlador |
| Se anota en una **conexión aparte** | la petición denegada revierte la transacción; en la misma conexión el registro se perdería |
| `paciente_id` **sin clave foránea** | el registro tiene que sobrevivir a que la ficha se cierre |
| `usuario_id` con `nullOnDelete` | si una cuenta desaparece, el registro queda |
| El **detalle fila por fila no se expone** por la API | una pantalla con el detalle es una pantalla que se puede dejar abierta, y esa pantalla revela información de salud de pacientes |
| El registro dice quién miró, **no qué se vio** | la bitácora no guarda el contenido de la ficha que se abrió |

Acciones auditadas: `ver_estado_actual`, `ver_cronologia`,
`registrar_intervencion`, `ver_plan`, `editar_plan`.

### 5. El odontograma es inmutable, y eso lo impone la base

El estado de una pieza se cambia registrando una **intervención**: un hecho con
fecha, con profesional y con descripción.

La migración `2026_02_10_000021` pone tres disparadores que rechazan:

- borrar una intervención,
- mover una pieza sin una intervención que lo justifique,
- devolver a un estado natural una pieza extraída.

Es decir: **la inmutabilidad no depende de que la aplicación se porte bien.**

Y hay una máquina de estados que rechaza las transiciones que no existen en
odontología: una pieza extraída no vuelve a la boca, una pieza ausente es
terminal.

### 6. Sesión

- Cookie `HttpOnly` y `SameSite=Lax`.
- `Secure` cuando el despliegue es por HTTPS (`SESSION_SECURE_COOKIE=true`).
- Se **renueva la sesión al entrar** (`regenerate()`), para evitar el fijamiento
  de sesión (CWE-384).
- `CLINICA_DENTAL_EXPIRACION_SESION` corto: una sesión abierta mucho rato es una
  sesión abierta al historial odontológico.
- Una cuenta desactivada deja de operar en su sesión siguiente, antes de tocar la
  base de datos.

### 7. Autenticación

- Authorization code contra Authentik. Sin flujo implícito.
- El `id_token` **se verifica acá**: algoritmo, firma (JWKS), emisor, audiencia
  y expiración, con `firebase/php-jwt`. El canje del código lo hace
  `DescubridorOidc`, pero **validar el token es de esta aplicación**: sin esa
  verificación, aceptaría como válido un token emitido para otra aplicación del
  mismo emisor.
- El rol se resuelve de los equipos de Authentik **en cada ingreso**. Si en
  Authentik le quitan el equipo a alguien, pierde el acceso en la sesión
  siguiente.
- Sin equipo de consultorio, no hay ingreso: la cuenta entra pero la petición se
  corta en `ResolverTenant`.

### 8. Transporte y cabeceras

- Caddy termina TLS y obliga a HTTPS.
- `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`,
  `Content-Security-Policy` con `frame-ancestors 'none'`, `Permissions-Policy`.
- `Cache-Control: no-store` en **toda** respuesta, en las tres capas (Caddy,
  nginx, la propia aplicación): ni una agenda ni una cronología pueden quedar en
  el historial del navegador ni en un proxy intermedio. En un consultorio el
  equipo se turna en el mismo puesto, y el historial lo ve el siguiente.
- Registro de acceso de Caddy en JSON, sin cuerpos ni cookies.
- `file_uploads = Off`: no se sube nada, y no se abre la puerta a que se empiece
  sin que nadie lo decida.

### 9. Superficie expuesta

En producción **solo Caddy pública puertos**. PostgreSQL, Redis, la API y la
interfaz no se alcanzan desde afuera.

En desarrollo los puertos están atados a `127.0.0.1`, no a `0.0.0.0`.

### 10. Registro y secretos

- `APP_DEBUG=false` y `LOG_LEVEL=info`: con `debug`, el cuerpo de una petición
  puede quedar en el registro, y el cuerpo puede ser la descripción de una
  intervención.
- Ningún secreto en el código del navegador: la sesión es una cookie que viaja
  por el BFF.
- `.env.example` sin valores.

---

## CONSERVACIÓN: por qué no hay un plazo

Esta es la parte donde más fácil sería inventar un dato, y por eso está escrita
con tanto detalle.

### Lo que dice la configuración

```php
'conservacion' => [
    'bitacora_accesos_dias'  => $enteroONulo(env('CLINICA_DENTAL_RETENCION_BITACORA_DIAS')),
    'intervenciones_dias'    => $enteroONulo(env('CLINICA_DENTAL_RETENCION_INTERVENCIONES_DIAS')),
    'avisar_antes_de_purgar' => false,
],
```

**Por omisión vale `null`.** Y `null` significa exactamente:

> **"No hay plazo configurado."**

No es cero. No es diez años. No es "para siempre, automáticamente". Es que
**falta la decisión.**

### Por qué no se puso un número

La Ley 19.628 obliga a que la conservación tenga una finalidad declarada, pero
**no se pudo verificar en este entorno** el plazo que corresponde a una ficha
odontológica. Su articulado no se pudo leer (el sitio del BCN carga con
JavaScript).

Un plazo inventado sería el tipo de dato que después nadie sabe defender: en una
auditoría, "¿por qué diez años?" no tiene respuesta, y la respuesta que se da
("lo pusimos por defecto") es la peor posible.

### Qué hace el sistema con el plazo vacío

**No purga nada.** Nunca.

La consecuencia es deliberada y es la razón de esta decisión: **borrar historia
clínica porque pasó un número inventado es peor que no borrar nunca.** La
historia clínica no se reconstruye, y una historia clínica incompleta por una
purga automática es un daño que no se puede deshacer.

### Cuándo se configura

Solo cuando el consultorio cliente, **con su revisión legal**, tenga un plazo
escrito en su documento de tratamiento de datos. Va en **días**.

Y aunque se configure, la purga **no está implementada**: la variable queda leída
y nada más. Está anotado en [10](10-PRUEBAS-Y-ESTADO.md).

### Lo mismo con el desbloqueo de alergias

Por omisión un desbloqueo **no vence**: vale hasta que un profesional lo cierre a
mano. El plazo de vigencia (`CLINICA_DENTAL_VIGENCIA_DESBLOQUEO_DIAS`) también
nace vacío, por la misma razón: cuánto puede durar una autorización de una
atención sobre una alergia es una decisión clínica del consultorio.

---

## Lo que NO está hecho

Con esto a propósito, y dicho con todas las letras:

| Falta | Por qué importa |
|---|---|
| **Registro de consentimiento** | sin él no hay forma de demostrar que el paciente autorizó el tratamiento de sus datos de salud |
| **Mecanismo de derechos del titular** (acceso, rectificación, supresión) | la ley los reconoce y el sistema no los implementa |
| **Doble factor de autenticación** | el acceso a una ficha odontológica con solo una contraseña robada es un incidente |
| **Cifrado de los respaldos** | el respaldo es la copia más expuesta del dato. Ver [08](08-RESPALDOS.md) |
| **Política de respaldo probada y restaurada** | un respaldo no probado no es un respaldo |
| **Validación del registro profesional** | `registro_profesional` es texto libre. La pantalla lo marca como "sin verificar" |
| **Inmutabilidad de la bitácora a nivel de modelo** | hoy un registro se puede modificar con un `UPDATE` directo. El modelo `AccesoOdontograma` no impone la restricción. La tabla sí tiene RLS, así que solo se escribe desde el contexto del consultorio, pero eso no es lo mismo que sea inmutable |
| **Bitácora firmada criptográficamente** o escrita a append-only | hoy la inmutabilidad depende de que nadie escriba en la tabla |
| **Alertas automáticas** por volumen de denegados | los umbrales existen en la configuración; el aviso **no**. La revisión es manual |
| **Rotación de la bitácora** según el plazo configurado | no implementada, porque el plazo nace vacío |
| **Aviso de privacidad y textos de consentimiento** redactados | es un trabajo legal, no una línea de código |
| **Exportación de datos** del titular | no implementada |
| **Radiografías e imágenes** | no hay subida de archivos en esta versión |
| **Odontograma infantil** (dentición decidua) | el modelo solo maneja 32 piezas permanentes |
| **Retirar un implante que fracasa** | el estado `implante` es terminal en esta versión. Hay que registrar una intervención nueva, y la máquina de estados no modela ese camino |
| **Concurrencia optimista en intervenciones** | dos ventanas podrían pisarse una intervención; hoy la última gana |
| **Prueba de penetración** por terceros | nunca se hizo |
| **Revisión jurídica de este documento** | ver el aviso del principio |

---

## Qué hacer antes de producción

1. **Revisión jurídica** de los textos de consentimiento, del aviso de privacidad
   y de **este documento**, incluido el plazo de conservación.
2. **Implementar el registro de consentimiento** y los mecanismos de derechos del
   titular. Es lo que falta, y no se puede sustituir con un documento.
3. Designar responsable y cumplir los derechos del titular.
4. Acuerdos documentados con cada profesional que use el sistema, sobre
   confidencialidad.
5. **Activar doble factor en Authentik.**
6. **Cifrar los respaldos** y resolver dónde se copian.
7. **Ensayar una restauración de verdad**, no solo una copia.
8. **Prueba de penetración por terceros**, con foco en el aislamiento entre
   consultorios y en el bypass de permisos.
9. Plan de respuesta ante incidentes, escrito y ensayado.
10. Validar el registro de profesionales contra una fuente oficial.
11. `php artisan clinica:verificar-aislamiento` en cada despliegue, y las
    tres suites de pruebas en cada entrega.

---

## Revisiones de la bitácora

La pantalla de administración permite filtrar por rango y muestra cuentas por
resultado, por acción y por persona.

Dos revisiones que conviene hacer:

- **Mensual:** intentos denegados por persona. Si aparecen muchos de la misma
  persona, hay que conversar con ella o corregir sus permisos.
- **Bajo demanda:** accesos de un paciente concreto, por ejemplo cuando el
  paciente o su familia pregunta quién miró su ficha. La respuesta tiene que
  salir de ahí, no de la memoria.

Los umbrales `CLINICA_DENTAL_ALERTA_DENEGADOS_POR_HORA` y
`CLINICA_DENTAL_ALERTA_DENEGADOS_POR_USUARIO` están en la configuración, pero el
aviso automático **no está implementado**: hoy la revisión es manual, y la
pantalla lo dice con todas las letras.

---

→ [08](08-RESPALDOS.md) para el respaldo, que es la copia más expuesta del dato.
→ [10](10-PRUEBAS-Y-ESTADO.md) para lo que se probó y lo que no.