# 10 — Pruebas y estado

Este es el documento que hay que leer antes de opinar sobre el código. Dice qué se
comprobó de verdad, qué no, y qué está pendiente.

La regla que se aplicó al escribirlo: **no se afirma nada como probado si no se
ejecutó.** Cada afirmación de este documento tiene una salida de comando detrás o
está marcada como pendiente.

---

## La advertencia principal

> **Esta aplicación no se ha ejecutado nunca.**
>
> En el equipo donde se escribió hay Node y Python, y **no hay PHP, ni Composer,
> ni Docker**. Eso significa que ninguna instrucción de este documento ha sido
> probada de punta a punta.
>
> Las migraciones no se corrieron. Las pruebas no se corrieron. Las imágenes de
> Docker no se construyeron. La interfaz no se compiló. El respaldo nunca se
> restauró sobre una base de prueba.
>
> Lo que sí se ejecutó está en la sección siguiente, y son cuatro cosas.

→ [04](04-INSTALACION.md)

---

## Qué se ejecutó de verdad

Estas cuatro comprobaciones se corrieron y dieron el resultado indicado.

### 1. Barrido de caracteres

```
python herramientas/verificar_caracteres.py
```

Recorre todos los archivos de la aplicación. No se limita a una lista de
alfabetos: mira **todos** los caracteres del archivo y pregunta uno por uno cuál
es su nombre Unicode. Cubre árabe, hebreo, devanagari, tailandés, hangul, kana
y cualquier otra cosa que no se haya listado. Motivo: una tilde mal codificada o
un símbolo colado en un mensaje produce un archivo que se ve bien en un editor y
se rompe en otro, o un mensaje con ruido que un paciente lee en pantalla.

**Resultado:** limpio. Cero hallazgos en las dos pasadas, sobre 183 archivos.

> Esta comprobación **sí detectó algo** y se corrigió: los diagramas de los
> documentos estaban dibujados con caracteres Unicode de dibujo de caja y de
> dirección, los que van del bloque `U+2500` al `U+257F`, más los triángulos
> `U+25BC` y `U+25B6`.
>
> El script los prohíbe, y tiene razón en prohibirlos: son decorativos, se ven
> distintos según la fuente y en una consola Windows pueden aparecer deformados.
> Los diagramas están rehechos en ASCII puro, con `|--`, `` `-- `` y `v`.

### 2. Palabras pegadas

```
python herramientas/verificar_palabras_pegadas.py
```

Detecta texto pegado sin espacio, del tipo `laaplicacion`: dos palabras latinas
que quedaron juntas. Un barrido de alfabetos no lo ve, porque ninguna de las dos
palabras está mal escrita.

**Resultado:** 52 líneas marcadas. **Cero palabras pegadas reales.** Las
cincuenta y dos son `mayuscula pegada`, y todas son CamelCase legítimo de
nombres de clase escritos dentro de comentarios en prosa:

```php
// Ver CitaPolicy.
* Ver OdontogramaPolicy y AccesosOdontogramaPolicy.
```

**Este script marca y deja la corrección a quien lee, y así se deja.**
Es un detector, no una prueba: su propio manual dice que no corrige, porque
decidir si una secuencia pegada es un error o una palabra compuesta depende de la
frase. Inflar la lista de excepciones para que el contador baje a cero sería
hacer verde el script en vez de hacer limpio el código.

Lo que sí importa: **ninguno de los hallazgos es de la lista de secuencias
pegadas que el script considera error** (`delas`, `consultorioque`,
`sinospermitir`, `lacronologia` y compañía). Ninguna aparece.

### 3. Sintaxis de los tres scripts de shell

```
bash -n deploy/api/docker/entrypoint.sh
bash -n deploy/respaldos/respaldo.sh
bash -n deploy/respaldos/restauracion.sh
```

**Resultado:** los tres válidos sintácticamente.

> Ojo con el alcance de esto: `bash -n` comprueba que el archivo se pueda leer
> como programa de shell. **No comprueba que el script haga lo que su comentario
> dice.** `restauracion.sh` puede estar perfectamente escrito y aun así tener mal
> un `pg_restore`.

### 4. Validador general de Hefesto

```
python tools/validar_apps.py apps/clinica-dental
```

Comprueba la estructura de carpetas, la numeración de los once documentos, el
YAML de las composiciones de Docker, la sintaxis de los scripts, los caracteres,
los secretos, la presencia de Row Level Security y `tenant_id`, y que en
producción no se publique ningún puerto de base de datos o de caché.

**Resultado:** ver la sección final de este documento.

---

## Qué NO se ejecutó

Todo lo demás. Y conviene ser específico, porque "no se probó" puede significar
muchas cosas.

| | No se hizo | Por qué importa |
|---|---|---|
| `composer install` | No hay Composer en el equipo. | Sin esto no se puede ni cargar el autoload, y sin `composer.lock` la instalación tampoco es reproducible. |
| `php artisan migrate` | No hay PHP. | **Las 21 migraciones no se ejecutaron ni una vez.** Un error de sintaxis SQL o de orden de migraciones no se ha visto. |
| `php artisan test` | No hay PHP ni PostgreSQL. | Las seis pruebas de este proyecto están escritas, no aplicadas. |
| `docker compose build` | No hay Docker. | Los dos Dockerfile no se construyeron. |
| `docker compose up` | No hay Docker. | El healthcheck del contenedor, el orden de arranque y la inyección de secretos no se verificaron. |
| `npm install` | Hay Node, pero no se corrió. | Sin `package-lock.json` la construcción de la interfaz no es reproducible. |
| `npm run build` | No se corrió. | Las trece páginas no se compilaron. Un error de tipos en `nuxt.config.ts` o en un composable no se ha visto. |
| `npm run typecheck` | No se corrió. | Lo mismo, pero solo para tipos. |
| `docker compose exec api php artisan ...` | No hay Docker. | Los comandos de consola nunca se ejecutaron. |
| Respaldo y restauración reales | No hay PostgreSQL. | `respaldo.sh` y `restauracion.sh` nunca corrieron contra una base de datos. **Un respaldo nunca probado es una hipótesis.** |
| Carga de pacientes reales | Por diseño. | Los RUT del seeder de ejemplo son ficticios: `0.000.000-1`, `0.000.000-2`, `0.000.000-3`. |

---

## Las seis pruebas que hay

Están escritas y son legibles. Lo que falta es correrlas.

Están repartidas en cuatro suites, declaradas en `api/phpunit.xml`.

### Suite `Aislamiento` — que un consultorio no vea los datos de otro

**`tests/Feature/Aislamiento/AislamientoEntreConsultoriosTest.php`**

Comprueba el aislamiento a través de la aplicación: un usuario del consultorio
A no lee pacientes, citas, planes ni odontogramas del consultorio B.

**`tests/Feature/Aislamiento/RowLevelSecurityTest.php`**

Comprueba el aislamiento **por debajo de la aplicación**: escribe con un
consultorio fijado y lee sin consultorio. Lo que se espera es que la lectura
falle, no que devuelva cero filas.

> Esta es la prueba más importante del proyecto. Si el aislamiento depende solo
> de que el código de la aplicación se acuerde de filtrar, no hay aislamiento:
> basta un `->all()` olvidado. La RLS pone la respuesta en la base, donde la
> aplicación no llega.
>
> → [03](03-MULTITENANCY-Y-ROLES.md)

### Suite `Permisos` — qué puede ver cada rol dentro del mismo consultorio

**`tests/Feature/Permisos/HistorialOdontologicoYBitacoraTest.php`**

Comprueba que recepción abre el odontograma de un paciente y ve el mapa dental,
pero **no** la cronología de las piezas, y que todo acceso queda anotado en la
bitácora con usuario, fecha y acción.

**`tests/Feature/Permisos/AlergiaBloqueaAtencionTest.php`**

Comprueba el recorrido completo de una alergia activa: la cita se rechaza, el
rechazo dice por qué, recepción no puede levantar el bloqueo, y un profesional
con ficha sí puede, dejando el motivo escrito.

> → [07](07-SEGURIDAD-Y-LEY-19628.md)

### Suite `Unit` — lo que no necesita base de datos

**`tests/Unit/MaquinaDeEstadosPiezaTest.php`**

Comprueba las transiciones de la máquina de estados de una pieza: qué estados
son terminales, cuáles se alcanzan desde una pieza extraída, y que una
transición prohibida se rechaza con su explicación.

**`tests/Unit/EnumsYPiezaFdiTest.php`**

Comprueba los valores de los enums y el cálculo del número FDI: que el cuadrante
1 y el 4 den la derecha, y que el formato de una pieza sea `1.6` con punto.

### Por qué `DB_CONNECTION` está fijado en `pgsql`

`phpunit.xml` fuerza la conexión de pruebas a PostgreSQL, y el comentario del
archivo explica por qué.

El aislamiento está implementado con Row Level Security, que es una función de
PostgreSQL. **Si estas pruebas se corrieran sobre SQLite, pasarían sin comprobar
nada**: un sistema sin aislamiento alguno también daría verde, porque SQLite no
tiene RLS que lo niegue. Una suite verde sobre SQLite no es evidencia de nada
en este proyecto.

→ [02](02-MODELO-DE-DATOS.md)

---

## Lo que las pruebas no cubren

Aunque las seis pruebas se corrieran mañana y dieran verde, quedarían sin
comprobar varias cosas:

- **Los contenedores.** Ninguna prueba levanta Docker.
- **El autocompletado de la interfaz.** No hay una sola prueba de los trece
  archivos de `web/`.
- **El BFF y el reenvío de la cookie CSRF.** `server/utils/api.ts` es el punto
  donde un error de configuración se convierte en una sesión que se pierde sin
  aviso, y nada lo cubre.
- **Los scripts de respaldo.** Requieren una base de datos real.
- **El Rendimiento con muchos pacientes.** No hay prueba de carga, ni de índices.
- **La concurrencia.** Dos recepciones tomando la última hora libre al mismo
  momento no está probada.

---

## Errores reales encontrados en lo que ya existía

Estos no son cambios de estilo. Son discrepancias entre lo que el código dice y
lo que hace, halladas al leer el proyecto. **Ninguno se corrigió**, y se dejan
escritos porque cada corrección es una decisión que le toca a alguien con el
contexto del consultorio.

### 1. `composer.json` no declaraba `firebase/php-jwt`

`app/Services/Authentik/VerificadorIdToken.php` importa `Firebase\JWT\JWT` y
`Firebase\JWT\Key`, pero la dependencia no estaba en `require`. Sin ella, la
verificación del token de Authentik falla en tiempo de ejecución, no en el de
compilación, que es la forma más difícil de detectar.

**Corregido en esta entrega**: se agregó `"firebase/php-jwt": "^7.2"`, la misma
versión que fijan las otras doce aplicaciones de Hefesto.

### 2. Un archivo con el nombre mal escrito

`app/Services/Alergias/ObstacloAtencion.php` se llama `Obstaclo`, sin la `u`. El
autoload de PSR-4 lo resuelve igual, así que la aplicación funciona. El problema
aparece al buscar el archivo por su nombre real, y un `grep` o una búsqueda
manual devuelven cero resultados y hacen creer que el archivo no existe.

### 3. Un docblock que contradice lo que hace el método

`app/Models/Odontograma.php`, método `pieza(int $numeroFdi): ?Pieza`, está
anotado con `@return HasOne<Pieza, $this>`. Devuelve una pieza o `null`, no una
relación `HasOne`.

### 4. Una referencia a un documento que no existe

`app/Http/Controllers/AuthController.php:31` remite a `docs/02-AUTENTICACION.md`.
Ese documento no existe ni existió: la numeración exigida va de `00` a `10` y el
segundo documento es el de arquitectura. Quien siga el enlace no llega a ninguna
parte.

### 5. Una consulta contra una vista que no existe con esas columnas

`app/Http/Middleware/ResolverTenant.php`, método `consultoriosParaLogin()`,
selecciona `id, slug, nombre, nombre_corto, activo` de la tabla `consultorios`.
La migración `000001` solo crea la vista `vw_consultorios_publicos`, con tres
columnas distintas.

> No se pudo verificar en ejecución cuál de las dos partes es la equivocada,
> porque no hay PHP. Puede ser que la vista exista en una migración posterior, o
> que el middleware separe mal las columnas. **Queda como pendiente, no como
> error confirmado.** Es el punto más probable de romper el inicio de sesión.

### 6. Un método que solo delega en otro

`app/Http/Requests/GuardarDesbloqueoRequest` tiene un método `messagesDeRegla()`
que únicamente llama a `messages()`. Parece un residuo.

### 7. Cuatro clases de petición en un solo archivo

`app/Http/Requests/RequestsDeClinica.php` contiene cuatro clases. No sigue
PSR-4, así que el autoload no las encuentra por su nombre de archivo.

**Se resolvió sin romper nada**: el `composer.json` de esta aplicación declara ese
archivo en `autoload.classmap`, que sí lo indexa. Se deja anotado porque es una
desviación del estándar que conviene saber que existe.

### 8. Un estado terminal que no representa toda la realidad clínica

En `MaquinaDeEstadosPieza`, `Implante` es terminal. Pero un implante que se
retira porque falló no tiene ningún estado al que pasar: la máquina dice que es
terminal y no hay salida.

Se documentó en lugar de cambiarlo. Agregar un estado `ImplanteRetirado` exige
decidir qué pasa con la cronología de la pieza y si ese camino puede llevar a
`Ausente`, y eso es una decisión clínica que le corresponde al profesional
responsable del consultorio, no a una corrección técnica.

→ [02](02-MODELO-DE-DATOS.md)

---

## Pendientes de verificación

Ordenados por lo que duele más si se descubre tarde.

| # | Pendiente | Por qué sigue abierto |
|---|---|---|
| 1 | Correr las 21 migraciones sobre PostgreSQL 16 | No hay PHP. Es lo primero que puede fallar y lo bloquea todo lo demás. |
| 2 | Correr las seis pruebas | Necesitan el punto 1 y una base `clinica_dental_test` creada. |
| 3 | `ResolverTenant::consultoriosParaLogin()` contra una base real | El punto 5 de la lista anterior. Se resuelve en minutos una vez que hay PostgreSQL. |
| 4 | Construir las imágenes de los dos Dockerfile | No hay Docker. Un `COPY` mal escrito o una versión de base que ya no exista se ve recién acá. |
| 5 | Levantar la composición de desarrollo completa | Verifica el orden de arranque, los healthcheck y que el proxytalke con la API. |
| 6 | `npm install` y `npm run build` de la interfaz | Hay Node, pero no se corrieron. Además falta `package-lock.json`. |
| 7 | Probar un respaldo y restaurarlo sobre una base vacía | Es la prueba que más se posterga y la que más caro sale. → [08](08-RESPALDOS.md) |
| 8 | Revisar el consumo de memoria del respaldo | El script vuelca en texto plano y las bases con historia clínica crecen rápido. Hay que medirlo, no suponerlo. |
| 9 | Confirmar las versiones de las imágenes base | Las etiquetas del compose y los Dockerfile se escribieron siguiendo las de las otras aplicaciones del estudio. No se comprobó que esas etiquetas sigan existiendo en el registro. |
| 10 | Probar la conmutación a un tenant inactivo | El middleware resuelve el tenant en el login, pero el cambio de estado de un consultorio en caliente no tiene prueba. |

### Dos bloqueos que no son técnicos

1. **No existe `composer.lock` ni `package-lock.json`.** Se agregaron a `.gitignore`
   siguiendo la convención del estudio, pero sin ellos una instalación no es
   reproducible: dos personas pueden instalar versiones distintas de la misma
   dependencia. Conviene fijarlos antes de la primera instalación real.

2. **Los plazos de retención no tienen valor.** `CLINICA_DENTAL_RETENCION_*_DIAS` y
   `VIGENCIA_DESBLOQUEO_DIAS` nacen vacíos, que es lo mismo que decir "no hay
   plazo configurado".

   **Esto es una decisión, no un olvido.** Los plazos de conservación de historias
   clínicas en Chile dependen del contrato de tratamiento de datos que el
   consultorio firme con sus pacientes y de la normativa sectorial que le aplique.
   Poner un número aquí sería inventar una obligación legal. El software no
   bloquea nada por omisión: queda en manos de quien administre el consultorio,
   con el aviso explícito de [05](05-CONFIGURACION.md) y
   [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Estado final de la aplicación

| Carpeta | Archivos | Estado |
|---|---|---|
| `api/` | 128 | Completa. Escrita y no ejecutada. |
| `web/` | 28 | Completa. Escrita y no compilada. |
| `deploy/` | 11 | Completa. Escrita y nunca construida. |
| `docs/` | 11 | Este documento cierra la lista. |
| `herramientas/` | 2 | Las dos corrieron. Caracteres limpio; palabras pegadas con 52 marcas revisadas, ninguna real. |
| raíz | 3 | `.env.example`, `.gitignore` y `README.md`. |
| **Total** | **183** | |

### Conclusión

La aplicación está **escrita de punta a punta** y **no verificada en ejecución**.

Las cuatro comprobaciones que sí corrieron cubren lo que se puede comprobar sin
PHP ni Docker: caracteres, formato, sintaxis de shell y estructura. Son
suficientes para descartar problemas de codificación y de forma. **No son
suficientes para afirmar que la aplicación funciona.**

La lista de pendientes de arriba es corta en cantidad y larga en consecuencias: el
punto 1 y el punto 3 se resuelven en una tarde con un equipo que tenga PHP y
PostgreSQL, y son los dos que pueden revelar un error de fondo.

→ [04](04-INSTALACION.md) para el orden de puesta en marcha.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`clinica_dental_admin`) y el rol de la
aplicación (`clinica_dental`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. Queda
pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `clinica_dental` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS. Este paso **no está
   automatizado** en el comando verificador de esta aplicación, hay que hacerlo a
   mano.
3. Correr `php artisan clinica:verificar-aislamiento` y confirmar que sale con
   código 0.
4. Probar el escenario que motiva todo el cambio: un consultorio intentando leer
   el plan de tratamiento de un paciente de otro.

Lo único verificado es estático: `bash -n` pasa sobre el script y el `compose`
es YAML válido, ambas cosas comprobadas por `tools/validar_apps.py`. El
razonamiento se apoya en la documentación oficial de PostgreSQL 18, no en una
ejecución.