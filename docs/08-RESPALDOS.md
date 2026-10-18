# 08 — Respaldos

## Por qué este respaldo importa más que en otros sistemas

En un consultorio, perder la base no es perder una lista de clientes. Es perder:

- la respuesta a **"qué le hicimos a la pieza 16 en esa visita"**,
- y con ella la capacidad de atender a ese paciente hoy,
- y el registro de a quién se le Fizó una alergia anafiláctica.

El respaldo tiene que incluir el **historial odontológico con sus fechas**, no
solo el estado de hoy.

Y hay una segunda cosa, igual de importante: el respaldo es **la copia más
expuesta del dato**. Está en un disco, en un servidor, sin cifrar. Quien tenga
acceso al archivo tiene acceso a la historia clínica de todos los pacientes.

---

## Los dos scripts

| Script | Qué hace |
|---|---|
| `deploy/respaldos/respaldo.sh` | respaldo |
| `deploy/respaldos/restauracion.sh` | restauración |

Los dos son de shell puro, con `set -eu`, y no dependen de la aplicación.

---

## Respaldo

```bash
deploy/respaldos/respaldo.sh
deploy/respaldos/respaldo.sh --destino /mnt/respaldos --retencion 90
```

### Variables de entorno

Normalmente vienen de `api/.env`:

| Variable | Para qué |
|---|---|
| `DB_HOST`, `DB_PORT` | dónde está PostgreSQL |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | con qué credenciales |
| `DIRECCION_RESGARDOS` | dónde se dejan los archivos (por omisión, `/var/backups/clinica-dental`) |
| `RETENCION_DIAS` | por omisión, `30` |

### Qué deja

Para un respaldo del 2026-10-17 a las 03:00:

```
/var/backups/clinica-dental/
|-- clinica-dental-20261017-030000.dump
|-- clinica-dental-20261017-030000.manifiesto.txt
`-- clinica-dental-20261017-030000.completo
```

### Las seis reglas que sigue

#### 1. Manifiesto con SHA-256 de cada archivo

El manifiesto tiene el tamaño, la base de datos y la suma:

```
$ sha256sum -c clinica-dental-20261017-030000.manifiesto.txt
clinica-dental-20261017-030000.dump: OK
```

Un respaldo sin suma de verificación es un archivo: no se puede saber si se
restauró completo hasta que ya es tarde.

#### 2. Se marca COMPLETO solo al final

El `.completo` se escribe **al final**, después de verificar el archivo. Si el
proceso se corta a mitad de camino, el `.completo` no existe, y
`restauracion.sh` **rechaza** ese respaldo.

Marcarlo completo al empezar sería exactamente el error que hace que alguien
restaure medio respaldo creyendo que es entero.

Para que se vea todavía más claro, el `pg_dump` escribe primero en un archivo
`.parcial` y solo se renombra al terminar.

#### 3. Se validan las rutas antes de escribir

Antes de ejecutar una sola línea contra la base, el script comprueba:

- que estén `DB_HOST` y `DB_DATABASE`,
- que existan `pg_dump` y `sha256sum`,
- que la retención sea un número,
- que el destino sea una **ruta absoluta** (una ruta relativa depende de
  dónde se corra el script, y un respaldo guardado donde no se sabe es un
  respaldo que no se encuentra),
- que el destino sea un directorio escribible.

#### 4. El destino ocupado se rechaza

```
[respaldo] ERROR: ya existe un respaldo con ese nombre: ...
[respaldo]        No se sobreescribe nada. Use --force solo si sabe lo que hace.
```

Un respaldo que se sobrescribe por error es la forma más común de perder el
único respaldo que quedaba. `--force` existe, pero hay que pedirlo
explícitamente.

#### 5. Nunca hay DROP, nunca hay sobrescritura automática

En el respaldo no hay ningún `DROP`. Y la sobrescritura requiere `--force`.

#### 6. La retención no borra respaldos a medias

Al purgar, el script **no borra** un respaldo que no tenga su `.completo`: si
algo quedó a medias, esa es justamente la copia que alguien va a necesitar.

### Verificación

Al final, `pg_restore --list` lee el archivo. Si está dañado, el script falla y
**no borra ningún respaldo anterior**.

### Copia fuera del servidor

El script **no copia nada a otro lado**, a propósito: cada instalación decide
adónde va su copia (una NAS, un servicio de objetos, otro servidor) y esa
decisión no se puede tomar desde un script genérico.

Lo que sí hace es recordarlo al final, en la última línea que se ve en pantalla.

---

## Restauración

```bash
# Contra una base de prueba, primero
DB_DATABASE=clinica_dental_restauracion_prueba \
  deploy/respaldos/restauracion.sh \
  /var/backups/clinica-dental/clinica-dental-20261017-030000.dump --confirmar

# Con otra base destino
deploy/respaldos/restauracion.sh ARCHIVO.dump --confirmar --base otra_base
```

### Lo que este script NO hace, y es deliberado

| No hace | Por qué |
|---|---|
| **Nunca hace `DROP DATABASE`** | ni `dropdb`, en ninguna versión de este script. Esa es la operación irrecuperable. <br> **Ojo, matiz importante:** la restauración usa `pg_restore --clean --if-exists`, que internamente emite `DROP` de cada tabla, índice, vista y función **antes de recrearlos**. Eso no es un descuido: es lo que deja la base en el estado en que estaba el respaldo. Lo que nunca se borra es **la base**. Una app que quiera evitarlo por completo debe usar `TRUNCATE` en vez de `--clean`, como hace `apps/peluqueria`. |
| **No sobreescribe nada** sin `--forzar` | ver más abajo |
| **No restaura sobre `clinica_dental`** | el nombre de la base de la aplicación está prohibido explícitamente |
| **No borra el servicio** | lo **detiene**, y lo deja detenido |

Si lo que se quiere es empezar de cero, se crea una base nueva vacía con un
nombre distinto y se restaura ahí. Es más tedioso a propósito.

### El orden de las comprobaciones

Este orden importa, y cada paso evita el siguiente error:

1. **Confirma `--confirmar`.** Sin él, el script muestra qué va a hacer y sale.
2. **El archivo existe.**
3. **`DB_DATABASE` está definida** y **no** es `clinica_dental`.
4. **El respaldo tiene su `.completo`.** Si no lo tiene, no se restaura.
5. **El SHA-256 coincide con el manifiesto.**
6. **La base destino está vacía.** Si tiene tablas y no viene `--forzar`, el
   script falla:
   ```
   [restauracion] ERROR: la base 'X' ya tiene 24 tablas.
   [restauracion]               Este script NO sobrescribe nada por omision.
   ```
7. **El servicio se detiene**, antes de restaurar.
8. **Se restaura**, con `--single-transaction`: o entra todo, o no entra nada.
9. **Se comprueba que la base restaurada tiene las políticas de aislamiento.**
10. **El servicio queda DETENIDO.**

### El servicio queda detenido

Es lo correcto, y es lo contrario de lo cómodo.

Restaurar con el sistema arriba es la forma de que alguien escriba en la base a
mitad de la restauración. El resultado es un mosaico de dos épocas, que es peor
que no tener nada: parece restaurado.

Si la restauración falla, el servicio **también** queda detenido. Se levanta a
mano, cuando alguien haya mirado el resultado.

### Después de restaurar

```bash
# 1. Mirar el resultado.
docker compose -f deploy/docker-compose.prod.yml start api web

# 2. Antes de recibir el primer paciente:
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan clinica:verificar-aislamiento
```

Y una comprobación que no se puede automatizar: **abrir la interfaz y cuadrar los
antecedentes con la cronología**. Que las tablas tengan filas no significa que
los datos estén bien: un respaldo restaurado sobre una base con datos de otra
época puede tener filas y estar mal.

---

## Cifrado: lo que NO está hecho

**Los respaldos no están cifrados.**

`pg_dump --format=custom` comprime, pero no cifra. Quien tenga acceso al archivo
tiene acceso a la historia clínica de todos los pacientes.

Esto está anotado como carencia en [07](07-SEGURIDAD-Y-LEY-19628.md), y es una
de las cosas que hay que resolver antes de producción.

Las dos salidas razonables:

1. **Cifrar el destino** con el sistema de archivos cifrado del servidor (LUKS) o
   el de la NAS. Simple y no requiere tocar el script.
2. **Cifrar el archivo** con `age` o `gpg` antes de copiarlo, y descifrarlo en
   la restauración. Requiere modificar los dos scripts y manejo de claves.

Ninguna de las dos está implementada. La decisión depende del consultorio y de su
política de seguridad, y por eso no se tomó a ciegas aquí.

---

## Operación

### Automatización

No hay tarea programada declarada en la aplicación. La decisión de cuándo y con
qué frecuencia se respalda es del consultorio, y depende de la ley de
conservación que le aplique.

Una frecuencia razonable, que hay que confirmar con el cliente:

```cron
# Todos los días a las 3:00
0 3 * * * cd /opt/clinica-dental && deploy/respaldos/respaldo.sh >> /var/log/clinica-respaldo.log 2>&1
```

### Copia externa

**Es obligatoria.** Un respaldo en el mismo servidor del que se hace el respaldo
no es un respaldo: si se pierde el servidor, se pierden los dos.

Cada instalación decide adónde: una NAS, un servicio de objetos, otro servidor.
El script no lo copia a propósito, y lo recuerda.

### Ensayo de restauración

**Al menos una vez por trimestre**, sobre una base de prueba:

```bash
docker compose -f deploy/docker-compose.dev.yml exec postgres \
  createdb -U clinica_dental clinica_dental_restauracion_prueba

DB_DATABASE=clinica_dental_restauracion_prueba \
  deploy/respaldos/restauracion.sh ARCHIVO.dump --confirmar
```

Un respaldo que no se ha restaurado nunca es un respaldo del que nadie sabe si
sirve.

---

## Problemas frecuentes

### "pg_dump no está disponible"

El script necesita el cliente de PostgreSQL. En una máquina sin él:

```bash
docker run --rm -e PGPASSWORD="$DB_PASSWORD" \
  -v "$DESTINO:/respaldos" \
  docker.io/library/postgres:18.6 \
  pg_dump --host=... --dbname=... --format=custom --file=/respaldos/ARCHIVO.dump
```

### "Faltan DB_HOST o DB_DATABASE en el entorno"

Las variables vienen de `api/.env`. Cárguelas antes:

```bash
set -a; . ./api/.env; set +a
deploy/respaldos/respaldo.sh
```

### "Este respaldo NO tiene marca de completado"

Se cortó el respaldo a mitad. Es lo que debe pasar: es preferible a que alguien
restaure medio respaldo creyendo que es entero. Busque el respaldo anterior.

### "La base 'X' ya tiene N tablas"

El script se niega a sobrescribir. Eso es lo correcto: lo que hay ahí puede ser
la historia clínica de pacientes reales.

Si de verdad quiere reemplazarla, cree una base vacía con otro nombre.

---

→ [07](07-SEGURIDAD-Y-LEY-19628.md) para lo que un respaldo no protege.
→ [10](10-PRUEBAS-Y-ESTADO.md) para lo que se probó y lo que no.