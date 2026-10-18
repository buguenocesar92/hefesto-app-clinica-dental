#!/bin/sh
# =============================================================================
# Restauracion de un respaldo de ClinicaDental.
# =============================================================================
# CUANDO SE USA
# -------------
# 1. La base se danio y hay que volver al ultimo estado bueno.
# 2. Se prueba que el respaldo sirve. Una restauracion de prueba, al menos una
#    vez por trimestre.
#
# -----------------------------------------------------------------------------
# ADVERTENCIA, EN SERIO
# -----------------------------------------------------------------------------
# Este script RECONSTRUYE LA BASE COMPLETA. Lo que hay en la base de destino
# desaparece. En un consultorio, lo que hay en la base es el historial
# odontologico de pacientes reales, y no hay forma de recuperarlo si se pisa la
# base equivocada.
#
# Por eso este script:
#
#   - NUNCA hace DROP. Nunca. Ni de la base, ni de una tabla, ni de un esquema.
#     Un `DROP DATABASE` es irrecuperable y no hay version de este script que lo
#     habilite.
#   - NO SOBRESCRIBE NADA sin `--forzar`. Por omision falla si ya hay datos.
#   - DEJA EL SERVICIO DETENIDO al terminar. Restaurar con el sistema arriba es
#     la forma de que alguien escriba en la base a mitad de la restauracion y
#     que el resultado sea un mosaico de dos epocas.
#   - RECHAZA los respaldos sin marca de completo.
#   - VERIFICA el SHA-256 contra el manifiesto antes de tocar nada.
#
# USO
# ---
#   deploy/respaldos/restauracion.sh ARCHIVO.dump --confirmar [--forzar] [--base NOMBRE]
#
# Para una prueba, sobre una base vacia de nombre inequivoco:
#   DB_DATABASE=clinica_dental_restauracion_prueba \
#     deploy/respaldos/restauracion.sh /var/backups/clinica-dental/clinica-dental-20261017-030000.dump --confirmar
#
# Variables de entorno:
#   DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD
#   DB_DATABASE  base DESTINO (en pruebas suele ser una con nombre de prueba)
#   COMPOSE_FILE ruta al compose, para poder detener el servicio. Opcional.
# =============================================================================

set -eu

ARCHIVO=""
CONFIRMADO=0
FORZAR=0

# -----------------------------------------------------------------------------
# Opciones
# -----------------------------------------------------------------------------
while [ $# -gt 0 ]; do
    case "$1" in
        --confirmar)
            CONFIRMADO=1
            shift
            ;;
        --forzar)
            FORZAR=1
            shift
            ;;
        --base)
            DB_DATABASE="${2:-}"
            shift 2
            ;;
        -h|--help)
            echo "Uso: $0 ARCHIVO.dump --confirmar [--forzar] [--base NOMBRE]"
            exit 0
            ;;
        *)
            if [ -z "$ARCHIVO" ]; then
                ARCHIVO="$1"
                shift
            else
                echo "[restauracion] ERROR: argumento inesperado '$1'."
                exit 1
            fi
            ;;
    esac
done

if [ -z "$ARCHIVO" ]; then
    echo "Uso: $0 ARCHIVO.dump --confirmar [--forzar] [--base NOMBRE]"
    echo ""
    echo "La restauracion RECONSTRUYE la base completa. Nunca hace DROP."
    exit 1
fi

# -----------------------------------------------------------------------------
# Confirmacion
# -----------------------------------------------------------------------------
if [ "$CONFIRMADO" -ne 1 ]; then
    echo "ATENCION: esto va a reemplazar TODO el contenido de la base"
    echo "          ${DB_DATABASE:-sin definir}."
    echo ""
    echo "      archivo: $ARCHIVO"
    echo ""
    echo "Si lo que quiere es una PRUEBA, use una base de prueba:"
    echo "  DB_DATABASE=clinica_dental_restauracion_prueba $0 $ARCHIVO --confirmar"
    echo ""
    echo "Para confirmar, repita el comando agregando --confirmar."
    exit 1
fi

# -----------------------------------------------------------------------------
# Validaciones ANTES de tocar la base
# -----------------------------------------------------------------------------
if [ ! -f "$ARCHIVO" ]; then
    echo "[restauracion] ERROR: no existe el archivo $ARCHIVO"
    exit 1
fi

if [ -z "${DB_DATABASE:-}" ]; then
    echo "[restauracion] ERROR: falta DB_DATABASE en el entorno."
    exit 1
fi

# Una base cuyo nombre parezca de produccion no se restaura desde un script
# generico. Si es realmente lo que se quiere, hay que hacerlo a mano y con el
# operador mirando.
case "$DB_DATABASE" in
    clinica_dental)
        echo "[restauracion] ERROR: '$DB_DATABASE' es el nombre de la base de la aplicacion."
        echo "               Este script no restaura sobre ella: use una base de prueba"
        echo "               (por ejemplo clinica_dental_restauracion_prueba) o haga la"
        echo "               restauracion a mano, con el servicio detenido."
        exit 1
        ;;
esac

# -----------------------------------------------------------------------------
# MARCA DE COMPLETITUD
# -----------------------------------------------------------------------------
# Esta es la comprobacion mas importante de todas. `respaldo.sh` escribe el
# archivo `.completo` AL FINAL, cuando ya verificó el respaldo. Si no existe,
# el respaldo se hizo a medias y restaurarlo deja una base incompleta que
# parece entera: el peor resultado posible.
BASE_ARCHIVO="$(basename "$ARCHIVO" .dump)"
MARCA="$(dirname "$ARCHIVO")/$BASE_ARCHIVO.completo"

if [ ! -f "$MARCA" ]; then
    echo "[restauracion] ERROR: este respaldo NO tiene marca de completado."
    echo "               No se restaura: pudo cortarse el respaldo a mitad."
    echo "               Busque uno que tenga el archivo junto al .dump:"
    echo "               $MARCA"
    exit 1
fi

echo "[restauracion] respaldo marcado como completo."

# -----------------------------------------------------------------------------
# Verificacion de integridad contra el manifiesto
# -----------------------------------------------------------------------------
MANIFIESTO="$(dirname "$ARCHIVO")/$BASE_ARCHIVO.manifiesto.txt"

if [ -f "$MANIFIESTO" ]; then
    echo "[restauracion] verificando el SHA-256 contra el manifiesto..."

    if ! sha256sum -c --ignore-missing "$MANIFIESTO" >/dev/null 2>&1; then
        echo "[restauracion] ERROR: el archivo no coincide con su manifiesto. No se toco la base."
        exit 1
    fi

    echo "[restauracion] Suma verificada. El archivo esta integro."
else
    echo "[restauracion] AVISO: no hay manifiesto junto al respaldo."
    echo "               Sin manifiesto no se puede verificar la integridad."
fi

# -----------------------------------------------------------------------------
# La base de destino tiene que estar VACIA
# -----------------------------------------------------------------------------
# Sin `--forzar`, no se pisa lo que hay. Es la regla que hace que un error de
# dedo no borre la historia clinica de un consultorio.
TABLAS="$(PGPASSWORD="${DB_PASSWORD:-}" psql \
    --host="${DB_HOST:-127.0.0.1}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME}" \
    --dbname="$DB_DATABASE" \
    --tuples-only \
    --no-align \
    --command="SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'" \
    2>/dev/null | tr -d '[:space:]')"

if [ -n "$TABLAS" ] && [ "$TABLAS" != "0" ]; then
    if [ "$FORZAR" -ne 1 ]; then
        echo "[restauracion] ERROR: la base '$DB_DATABASE' ya tiene $TABLAS tablas."
        echo "               Este script NO sobrescribe nada por omision."
        echo "               Para continuar con una base que ya tiene datos:"
        echo "                 $0 $ARCHIVO --confirmar --forzar"
        echo "               Y sepa lo que hace: lo que hay ahi se pierde."
        exit 1
    fi

    echo "[restauracion] AVISO: --forzar. La base '$DB_DATABASE' tiene $TABLAS tablas."
    echo "               Se van a REEMPLAZAR sus objetos por los del respaldo."
fi

# -----------------------------------------------------------------------------
# EL SERVICIO SE DETIENE ANTES DE RESTAURAR
# -----------------------------------------------------------------------------
# Restaurar con el sistema arriba es la forma de que alguien escriba en la base
# a mitad de la restauracion. El resultado es un mosaico de dos epocas, que es
# peor que no tener nada: parece restaurado.
COMPOSE="${COMPOSE_FILE:-deploy/docker-compose.prod.yml}"

if command -v docker >/dev/null 2>&1 && [ -f "$COMPOSE" ]; then
    echo "[restauracion] detiendo el servicio..."

    if ! docker compose -f "$COMPOSE" stop api web 2>/dev/null; then
        echo "[restauracion] ERROR: no se pudo detener el servicio."
        echo "               Restaurar con el sistema arriba deja la base a medias."
        exit 1
    fi

    echo "[restauracion] servicio detenido."
else
    echo "[restauracion] AVISO: no se encontro 'docker' o el archivo $COMPOSE."
    echo "               ASEGURESE de que la API este detenida antes de continuar."
fi

# Si algo falla despues de este punto, el servicio se queda detenido. Es lo
# correcto: se levanta a mano, cuando la persona haya mirado el resultado.
SERVICIO_DETENIDO=1

restaurar() {
    echo "[restauracion] restaurando sobre $DB_DATABASE..."

    # `--clean --if-exists` deja la base en el estado del respaldo: los objetos
    # que ya estaban y no estan en el respaldo se van.
    #
    # NOTA SOBRE LO QUE NO HAY EN ESTE SCRIPT
    # --------------------------------------
    # No hay `--if-exists` con `DROP DATABASE`, no hay `dropdb`, y no hay forma
    # de que este script borre la base. Si lo que se quiere es empezar de cero,
    # se crea una base nueva vacia con un nombre distinto y se restaura ahi.
    # Es mas tedioso a proposito.
    if PGPASSWORD="${DB_PASSWORD:-}" pg_restore \
            --host="${DB_HOST:-127.0.0.1}" \
            --port="${DB_PORT:-5432}" \
            --username="${DB_USERNAME}" \
            --dbname="$DB_DATABASE" \
            --clean \
            --if-exists \
            --no-owner \
            --single-transaction \
            --exit-on-error \
            "$ARCHIVO"; then

        echo "[restauracion] listo."
    else
        echo "[restauracion] ERROR: fallo la restauracion. Revise el estado de la base."
        echo "               El servicio sigue DETENIDO a proposito."
        return 1
    fi
}

# -----------------------------------------------------------------------------
# Comprobaciones DESPUES de restaurar
# -----------------------------------------------------------------------------
# No basta con que el archivo entre: hay que comprobar que la base restaurada
# puede operar. Y la primera de esas comprobaciones es el aislamiento, porque sin
# el, la base restaurada es inutilizable aunque tenga todos los datos.
comprobar() {
    echo ""
    echo "[restauracion] comprobando el aislamiento entre consultorios..."

    EXISTEN="$(PGPASSWORD="${DB_PASSWORD:-}" psql \
        --host="${DB_HOST:-127.0.0.1}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="$DB_DATABASE" \
        --tuples-only \
        --no-align \
        --command="SELECT count(*) FROM pg_policies WHERE policyname = 'politica_aislamiento_tenant'" \
        2>/dev/null | tr -d '[:space:]')"

    if [ -n "$EXISTEN" ] && [ "$EXISTEN" != "0" ]; then
        echo "[restauracion] hay $EXISTEN politicas de aislamiento en la base restaurada."
    else
        echo "[restauracion] AVISO: no se encontraron politicas de aislamiento."
        echo "               NO reciba pacientes hasta revisar esto."
        echo "               Ver docs/03 y corra: php artisan clinica:verificar-aislamiento"
    fi

    echo ""
    echo "[restauracion] recordatorio: los antecedentes deben cuadrarse con la cronologia."
    echo "[restauracion] Revise en la interfaz que las piezas con intervenciones tengan"
    echo "[restauracion] el estado que corresponde, y no solo que las tablas tengan filas."
}

restaurar
comprobar

echo ""
echo "[restauracion] EL SERVICIO QUEDA DETENIDO."
echo "[restauracion] Revise el resultado antes de levantar nada:"
echo "               docker compose -f $COMPOSE start api web"
echo ""
echo "[restauracion] Y despues, antes de recibir el primer paciente:"
echo "               docker compose -f $COMPOSE exec api php artisan clinica:verificar-aislamiento"

exit 0