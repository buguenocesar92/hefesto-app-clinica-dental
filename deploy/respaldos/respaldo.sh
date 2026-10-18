#!/bin/sh
# =============================================================================
# Respaldo de ClinicaDental.
# =============================================================================
# POR QUE ESTE RESPALDO IMPORTA MAS QUE EN OTROS SISTEMAS
# -------------------------------------------------------
# En un consultorio, perder la base no es perder una lista de clientes: es perder
# la respuesta a "que le hicimos a la pieza 16 en esa visita" y con ella la
# capacidad de atender a ese paciente hoy. El respaldo tiene que incluir el
# HISTORIAL ODONTOLOGICO con sus fechas, no solo el estado de hoy.
#
# REGLAS QUE ESTE SCRIPT RESPETA, Y POR QUE
# -----------------------------------------
# 1. Manifiesto con SHA-256 de cada archivo. Un respaldo sin suma de verificacion
#    es un archivo: no se puede saber si se Restoreo completo hasta que ya es
#    tarde. Con el manifiesto, la verificacion es una linea.
#
# 2. El respaldo se marca COMPLETO solo al final, y con un archivo aparte. Si el
#    proceso se corta a mitad de camino, el `.completo` no existe y un script de
#    restauracion tiene que rechazar ese respaldo. Marcarlo completo al empezar
#    seria exactamente el error que hace que alguien restaure medio respaldo
#    creyendo que es entero.
#
# 3. Se validan las rutas ANTES de escribir. Un `pg_dump` que escribe donde no
#    debe, o que se equivoca de directorio, no se detecta hasta que se necesita
#    el respaldo.
#
# 4. El destino ocupado se rechaza, salvo que se pase `--force`. Un respaldo que
#    se sobreescribe por error es la forma mas comun de perder el unico respaldo
#    que quedaba.
#
# 5. NUNCA se hace DROP, nunca hay sobrescritura automatica. Ver `restauracion.sh`,
#    que es donde eso importa de verdad.
#
# USO
# ---
#   deploy/respaldos/respaldo.sh [--destino DIRECTORIO] [--retencion DIAS] [--force]
#
# Variables de entorno obligatorias (normalmente vienen de `api/.env`):
#   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
#
# Un respaldo de un consultorio que no se prueba no sirve de nada: es una carpeta
# con archivos. Ver docs/08.
# =============================================================================

set -eu

# -----------------------------------------------------------------------------
# Opciones
# -----------------------------------------------------------------------------
DESTINO="${DIRECCION_RESGARDOS:-/var/backups/clinica-dental}"
RETENCION_DIAS="${RETENCION_DIAS:-30}"
FUERZA=0

while [ $# -gt 0 ]; do
    case "$1" in
        --destino)
            DESTINO="${2:-}"
            shift 2
            ;;
        --retencion)
            RETENCION_DIAS="${2:-}"
            shift 2
            ;;
        --force)
            FUERZA=1
            shift
            ;;
        -h|--help)
            echo "Uso: $0 [--destino DIRECTORIO] [--retencion DIAS] [--force]"
            exit 0
            ;;
        *)
            echo "[respaldo] ERROR: opcion desconocida '$1'."
            exit 1
            ;;
    esac
done

# -----------------------------------------------------------------------------
# Validaciones ANTES de escribir nada
# -----------------------------------------------------------------------------
echo "[respaldo] comprobando el entorno..."

for variable in DB_HOST DB_DATABASE; do
    valor="$(eval "printf '%s' \"\${$variable:-}\"")"

    if [ -z "$valor" ]; then
        echo "[respaldo] ERROR: falta $variable en el entorno."
        exit 1
    fi
done

if ! command -v pg_dump >/dev/null 2>&1; then
    echo "[respaldo] ERROR: pg_dump no esta disponible en este equipo."
    exit 1
fi

if ! command -v sha256sum >/dev/null 2>&1; then
    echo "[respaldo] ERROR: sha256sum no esta disponible. Sin el, el respaldo no se puede verificar."
    exit 1
fi

# La retencion tiene que ser un numero. Un valor con letras terminaria en un
# `find -mtime` que no hace lo que dice.
case "$RETENCION_DIAS" in
    ''|*[!0-9]*)
        echo "[respaldo] ERROR: la retencion tiene que ser un numero de dias (recibido: '$RETENCION_DIAS')."
        exit 1
        ;;
esac

# El destino tiene que ser un directorio absoluto. Una ruta relativa depende de
# donde se corra el script, y un respaldo guardado donde no se sabe es un
# respaldo que no se encuentra.
case "$DESTINO" in
    /*) ;;
    *)
        echo "[respaldo] ERROR: el destino tiene que ser una ruta absoluta (recibido: '$DESTINO')."
        exit 1
        ;;
esac

# Si el destino existe y NO es un directorio, o no se puede escribir en el, hay
# que decirlo ahora y no a mitad del `pg_dump`.
if [ -e "$DESTINO" ] && [ ! -d "$DESTINO" ]; then
    echo "[respaldo] ERROR: '$DESTINO' existe y no es un directorio."
    exit 1
fi

mkdir -p "$DESTINO"

if [ ! -w "$DESTINO" ]; then
    echo "[respaldo] ERROR: no se puede escribir en '$DESTINO'."
    exit 1
fi

# -----------------------------------------------------------------------------
# Nombre y rechazo de destino ocupado
# -----------------------------------------------------------------------------
# Las marcas de tiempo van sin espacios ni dos puntos: algunos sistemas de
# archivos no los aceptan, y el nombre del archivo importa cuando hay que
# encontrar un respaldo a las cuatro de la manana.
MARCA="$(date +%Y%m%d-%H%M%S)"
PREFIJO="clinica-dental-$MARCA"
ARCHIVO="$DESTINO/$PREFIJO.dump"

if [ -e "$ARCHIVO" ]; then
    if [ "$FUERZA" -ne 1 ]; then
        echo "[respaldo] ERROR: ya existe un respaldo con ese nombre: $ARCHIVO"
        echo "[respaldo]        No se sobreescribe nada. Use --force solo si sabe lo que hace."
        exit 1
    fi

    echo "[respaldo] AVISO: --force. Se va a sobrescribir $ARCHIVO."
fi

# -----------------------------------------------------------------------------
# El respaldo
# -----------------------------------------------------------------------------
echo "[respaldo] respaldando $DB_DATABASE en $ARCHIVO"

# Se escribe primero en un nombre temporal y se renombra al final. Si el proceso
# se corta a mitad, queda un `.parcial` que ningun restaurador toma por bueno.
PARCIAL="$ARCHIVO.parcial"

# Format personalizado de PostgreSQL: permite restaurar tablas sueltas, que es
# lo que se necesita cuando se daña una tabla de intervenciones y no todo lo
# demas. `--no-owner` evita que el respaldo exija un rol que puede que ya no
# exista cuando hay que restaurar.
if ! PGPASSWORD="${DB_PASSWORD:-}" pg_dump \
        --host="${DB_HOST}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="${DB_DATABASE}" \
        --format=custom \
        --compress=9 \
        --no-owner \
        --file="$PARCIAL"; then

    echo "[respaldo] ERROR: fallo pg_dump. No se borro ningun respaldo anterior."
    rm -f "$PARCIAL"
    exit 1
fi

mv "$PARCIAL" "$ARCHIVO"

# -----------------------------------------------------------------------------
# Verificacion: un respaldo sin probar no es un respaldo
# -----------------------------------------------------------------------------
echo "[respaldo] verificando que el archivo se puede leer..."

if ! pg_restore --list "$ARCHIVO" >/dev/null 2>&1; then
    echo "[respaldo] ERROR: el archivo respaldo esta danado. Investigue antes de seguir."
    exit 1
fi

SUMA="$(sha256sum "$ARCHIVO" | cut -d ' ' -f 1)"

if [ -z "$SUMA" ]; then
    echo "[respaldo] ERROR: no se pudo calcular la suma SHA-256."
    exit 1
fi

TAMANO="$(wc -c < "$ARCHIVO" | tr -d ' ')"

echo "[respaldo] listo: $ARCHIVO ($TAMANO bytes)"

# -----------------------------------------------------------------------------
# MANIFIESTO
# -----------------------------------------------------------------------------
# Se escribe con la suma de cada archivo. Es lo que permite verificar despues,
# sin la base, que el respaldo esta integro.
MANIFIESTO="$DESTINO/$PREFIJO.manifiesto.txt"

cat > "$MANIFIESTO" <<MANIFIESTO
# Respaldo de ClinicaDental
# Generado: $(date '+%Y-%m-%d %H:%M:%S %Z')
# Base de datos: $DB_DATABASE
# Anfitrion: $(hostname)
# Archivo de datos: $PREFIJO.dump
# Tamano en bytes: $TAMANO
#
# Para verificar la integridad mas adelante:
#   sha256sum -c $PREFIJO.manifiesto.txt
#
# Para restaurar, ver deploy/respaldos/restauracion.sh. Lea ese archivo antes:
# la restauracion REConstruye la base completa.

$SUMA  $PREFIJO.dump
MANIFIESTO

echo "[respaldo] manifiesto: $MANIFIESTO"

# -----------------------------------------------------------------------------
# MARCA DE COMPLETITUD
# -----------------------------------------------------------------------------
# Se escribe AL FINAL y a proposito. Mientras este archivo no exista, el
# respaldo se considera incompleto y `restauracion.sh` lo rechaza. Ver la regla
# 2 del encabezado.
COMPLETO="$DESTINO/$PREFIJO.completo"

cat > "$COMPLETO" <<COMPLETO
completo
generado=$(date '+%Y-%m-%d %H:%M:%S %Z')
archivo=$ARCHIVO
manifiesto=$MANIFIESTO
sha256=$SUMA
base=$DB_DATABASE
COMPLETO

echo "[respaldo] marcado como completo: $COMPLETO"

# -----------------------------------------------------------------------------
# Retencion
# -----------------------------------------------------------------------------
# La retencion borra respaldos VIEJOS. Nunca borra el recien hecho, y nunca
# borra un respaldo que no tenga su marca de completo: si algo quedo a medias,
# esa es justamente la copia que alguien va a necesitar.
echo "[respaldo] borrando respaldos completos de mas de $RETENCION_DIAS dias..."

for viejo in "$DESTINO"/clinica-dental-*.dump; do
    [ -e "$viejo" ] || continue

    base="$(basename "$viejo" .dump)"

    if [ ! -f "$DESTINO/$base.completo" ]; then
        echo "[respaldo]   conservando $base: no tiene marca de completo."
        continue
    fi

    if [ "$base" = "$PREFIJO" ]; then
        continue
    fi

    if find "$viejo" -mtime "+$RETENCION_DIAS" | grep -q .; then
        echo "[respaldo]   borrando $base"
        rm -f "$viejo" "$DESTINO/$base.manifiesto.txt" "$DESTINO/$base.completo"
    fi
done

# -----------------------------------------------------------------------------
# Copia fuera del servidor
# -----------------------------------------------------------------------------
# Este script NO copia nada a otro lado a proposito: cada instalacion decide
# adonde va su copia (una NAS, un servicio de objetos, otro servidor) y esa
# decision no se puede tomar desde un script generico. Lo que si se deja escrito
# es que la copia externa es obligatoria. Ver docs/08.

echo "[respaldo] recordatorio: este archivo sigue en este servidor."
echo "[respaldo] falta copiarlo fuera y PROBAR una restauracion sobre una base vacia."

ls -lh "$DESTINO" | tail -n 12