# 06 — Primer paciente

La sesión completa, paso a paso, con los tres roles. Para ver que el sistema
funciona **y que los permisos funcionan**.

> Todo lo que se cuenta aquí **no se ejecutó**. Es el guion previsto, escrito
> para que la persona que haga la prueba sepa qué mirar. Ver
> [10](10-PRUEBAS-Y-ESTADO.md).

---

## Antes de empezar

Tenga a mano **dos cuentas**, porque casi todo lo interesante pasa cuando el rol
cambia:

| Cuenta | Rol | Para qué |
|---|---|---|
| Recepción | `recepcion` | agendar, ver agenda y alergias |
| Odontólogo(a) | `profesional` con ficha | atender, registrar, desbloquear |

Y el procedimiento `clinica:verificar-aislamiento` debe haber dicho
`Aislamiento verificado`. Si no lo dijo, no siga. Ver
[03](03-MULTITENANCY-Y-ROLES.md).

---

## Paso 1 — Recepción registra al paciente

Con la cuenta de **recepción**, en **Pacientes**.

Lo que aparece es la ficha de **identificación**: nombre, RUT, teléfono,
previsión. No hay ningún campo de historia clínica, y eso es a propósito.

| Campo | Qué poner |
|---|---|
| RUT | el real, con puntos y guion |
| Nombres y apellidos | |
| Fecha de nacimiento | |
| Sexo | se puede dejar en "no informado". Un paciente que no quiere declarar su sexo no es un paciente sin sexo: es un paciente que no lo declaró |
| Teléfono, previsión | |

**Mirar:** el campo `notas administrativas` acepta hasta mil caracteres, no más.

> Es deliberadamente corto. Las notas largas son el lugar donde se cuelan datos
> clínicos sin permiso y sin auditoría. Si hace falta una nota clínica, va en la
> cronología de una pieza, que sí tiene auditoría.

---

## Paso 2 — El mapa se crea solo

En la misma pantalla, más abajo, aparece **Odontograma** con las 32 piezas, todas
en `sano`.

**Mirar:**

- Son 32, ni una más ni una menos. Las piezas de leche no están: esta versión
  maneja solo dentición permanente.
- Todas dicen `sano` **con su fecha**. El estado `sano` de una pieza que nadie ha
  evaluado es una afirmación, y por eso queda la fecha.
- **Recepción ve las notas de las piezas: ve `null`.** No es que estén vacías, es
  que su rol no las abre.

### Por qué el mapa se crea solo

En un consultorio el mapa siempre está. Obligar a que alguien lo cree a propósito
solo produce mapas que nunca se crean. Se puede desactivar con
`CLINICA_DENTAL_ALTA_ODONTOGRAMA_AUTOMATICA=false`.

---

## Paso 3 — Recepción ve las alergias, y no puede esquivarlas

En **Alergias**, se registra una alergia. Para este ejercicio:

| Campo | Qué poner |
|---|---|
| Sustancia | látex |
| Reacción | hinchazón de cara y dificultad respiratoria |
| Severidad | severa |
| Fecha de registro | hoy |

> **La severidad la pone el profesional, no el sistema.** El sistema la ordena y la
> muestra, pero no la deduce: una escala propia sería inventar criterio clínico.
> Para esta prueba, pulse el botón; en el flujo real, esto lo hace el profesional
> que evaluó al paciente.

También registre una **condición** con `bloquea atención = sí`. La condición
tiene una diferencia con la alergia: una alergia tiene sustancia y reacción; una
condición es más general, y el campo `bloquea_atención` lo marca el profesional,
también sin que el sistema lo deduzca.

---

## Paso 4 — Recepción intenta agendar. Y no puede.

En **Agenda**, intente agendar al paciente para cualquier procedimiento.

**Mirar:** el sistema **no lo deja**.

El mensaje dice qué alergia bloquea, con la sustancia, la reacción y la
gravedad. No dice "error", no dice "no se pudo": dice qué.

Y el botón **Agendar** aparece deshabilitado antes de apretarlo, porque la
pantalla consulta el bloqueo mientras uno elige el procedimiento.

> **Esto no es una falla del sistema.** Es lo que hace que este sistema sea
> distinto de una hoja de papel. Si el sistema dejara agendar, la pantalla
> estaría diciendo que no hay riesgo cuando hay una alergia sin tratar.

---

## Paso 5 — El profesional registra un desbloqueo

Entre a la ficha del paciente con la cuenta de **odontólogo(a)**.

En **Alergias y condiciones**, al lado de la alergia hay un botón **Autorizar**.

Aparecen dos cosas que no aparecen con recepción:

1. El botón **Autorizar**. Recepción no lo ve.
2. El texto que dice que la autorización queda en la historia del paciente con su
   nombre, la fecha y el motivo, y que **no se puede editar ni borrar después**.

El motivo es obligatorio y tiene al menos quince caracteres. Una decisión clínica
sin explicación no sirve para nada dentro de tres años.

Escriba algo real, del estilo: *"Testigo informado, con la medicación de
emergencia disponible en la unidad y el paciente consiente."*

**Mirar:**

- El desbloqueo aparece en la lista con **"Sin vencimiento"**.
- El motivo queda escrito.

> Por omisión un desbloqueo **no vence**: vale hasta que un profesional lo cierre.
> El plazo es una decisión del consultorio, y el software no pone uno por
> omisión. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Paso 6 — Ahora sí se puede agendar

Vuelva a **Agenda** con recepción. Esta vez el botón está habilitado y la cita se
agenda.

**Mirar, en la fila de la cita:** un distintivo que dice **"Sobre desbloqueo"**.

Eso no es una alerta. Es información: esa cita se agendó **con** una alergia
activa y **con** un desbloqueo registrado, y no porque no hubiera ninguna. En la
agenda del consultorio, una cita y otra se ven distintas, y esa diferencia es la
que sirve.

---

## Paso 7 — El profesional atiende y registra

Con la cuenta de **odontólogo(a)**, en la ficha del paciente.

1. Apriete la pieza que va a tratar, en el mapa.
2. Aparecen las transiciones que son válidas desde el estado actual.
3. Escriba **qué se hizo**. Se exige un mínimo de cinco caracteres.
4. **Registrar intervención.**

**Mirar:**

- La pieza cambió de estado.
- El texto dice que quedó en la cronología y firmado por usted.

Ahora abra **Historial odontológico**.

**Mirar:**

- Está **agrupado por día**, no por pieza. La pregunta que se le hace a un
  sistema no es "qué le hicieron en el molar de arriba a la derecha": es "qué le
  hicimos en esa visita".
- Cada línea muestra el cambio de estado: de qué estado a cuál.
- La columna final dice en qué estado está **hoy** la pieza.

---

## Paso 8 — Ahora mire qué NO puede hacer recepción

Este es el paso que más importa, y el que hay que hacer **con las dos cuentas
abiertas**.

| Pruebe esto, con **recepción** | Qué tiene que pasar |
|---|---|
| Abrir el historial odontológico | 404. No 403 |
| Ver las notas de las piezas | Null en todas |
| Registrar una intervención | El botón no está |
| Ver los planes de tratamiento | Dice que no se abren con su rol |
| Ver la bitácora de accesos | 404 |
| Registrar una alergia | El botón no está |
| Conceder un desbloqueo | El botón no está |
| Registrar el desenlace de una cita | El botón no está |

Y con el **administrador**:

| Pruebe esto | Qué tiene que pasar |
|---|---|
| Ver la cronología | **Sí puede** |
| Registrar una intervención | **No puede** |
| Conceder un desbloqueo | **No puede** |
| Ver la bitácora | **Sí puede** |

> Ver la historia y escribir en ella son dos capacidades distintas. La segunda
> exige firma: es el profesional que la hizo, con su nombre. La dirección del
> consultorio no es un cargo clínico.

### Por qué 404 y no 403

Un 403 confirma que el recurso existe. Si recepción se llevara un 403 al intentar
abrir el historial de un paciente, sabría que ese paciente existe en el sistema.

---

## Paso 9 — La bitácora

Entre con **administrador** y abra **Bitácora**.

**Mirar:**

- Hay accesos **permitidos** y accesos **denegados**.
- La pantalla dice, con todas las letras, que el aviso automático **no** está
  implementado. No promete algo que el sistema no hace.
- Los denegados de los pasos anteriores están ahí, con la hora.

### Qué mirar en esta pantalla

**Mensual:** intentos denegados por persona. Si una persona tiene muchos, hay
que conversar con ella o corregir sus permisos.

**Bajo demanda:** accesos a un paciente concreto, por ejemplo cuando el paciente
o su familia pregunta quién miró su ficha. La respuesta tiene que salir de ahí, no
de la memoria de nadie.

---

## Paso 10 — El desbloqueo vencido no sirve

Repita los pasos 4 a 6, pero con el desbloqueo cerrado o vencido.

**Mirar:** el sistema vuelve a bloquear.

Esa es la otra mitad de la prueba del paso 6: si la vigencia no se comprobara, un
desbloqueo de hace un año seguiría autorizando atenciones para siempre.

---

## Los cinco minutos que prueban el producto

Si solo tiene cinco minutos, haga estos tres pasos:

1. **Recepción** intenta agendar a un paciente con alergia activa → **no puede**.
2. **Profesional** registra un desbloqueo con motivo → **puede**.
3. **Recepción** mira la bitácora → el intento denegado del paso 1 **está**.

Si los tres funcionan, el núcleo del producto está. Todo lo demás son pantallas.

---

## Si algo no se comporta como este documento dice

1. Anote qué hizo, con qué cuenta y qué esperaba.
2. Revise si la cuenta tiene la ficha de profesional asignada. Sin ficha, un
   profesional puede **ver** la cronología pero no **escribir** en ella.
3. Corra `php artisan clinica:verificar-aislamiento`. (Si no existe en su
   versión, use `clinica:verificar-aislamiento`.)
4. **No lo arregle a mano en la base.** Un permiso que no funciona se arregla en
   el código y se vuelve a instalar. Si se arregla en la base, la próxima
   migración lo deshace y nadie se entera de por qué.

→ [07](07-SEGURIDAD-Y-LEY-19628.md) para lo que este ejercicio **no** prueba.
→ [10](10-PRUEBAS-Y-ESTADO.md) para lo que tampoco se ha probado por
automatización.