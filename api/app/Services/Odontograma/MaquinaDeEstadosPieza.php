<?php

declare(strict_types=1);

namespace App\Services\Odontograma;

use App\Enums\EstadoPieza;
use InvalidArgumentException;

/**
 * Máquina de estados de una pieza dentaria.
 *
 * ESTA CLASE RESPONDE A UNA PREGUNTA DEL ENCARGO
 * ------------------------------------------------
 * "Una pieza extraída no se restaura en el mismo registro como si nunca
 * hubiera existido. Y un implante en el lugar de una pieza extraída es un
 * evento distinto, no una corrección."
 *
 * La respuesta es que `extraida` no tiene retorno a los estados naturales.
 * Desde `extraida` solo se puede pasar a `implante`, `protesis` o `puente`,
 * y a ninguno más. Volver a `sano` u `obturado` después de una extracción
 * es imposible en dos niveles:
 *
 *   1. Aquí, en PHP, al registrar la intervención.
 *   2. En la base, con el disparador de la migración
 *      `2026_02_10_000021_proteger_cronologia_piezas.php`, que rechaza el
 *      `UPDATE` aunque el cambio venga de un `DB::statement` suelto.
 *
 * El nivel 2 existe porque el nivel 1 se puede saltar. Un script de
 * importación de un cuaderno viejo, o un error en una consola de PHP, pueden
 * escribir en `piezas` sin pasar por el servicio. La regla tiene que vivir
 * donde no se puede esquivar.
 *
 * `ausente` (la pieza nunca existió) es terminal en el otro sentido: no se
 * reemplaza, porque no hay nada que reemplazar.
 */
final class MaquinaDeEstadosPieza
{
    /**
     * Estados desde los que no se sale nunca.
     *
     * `ausente` es terminal: la pieza nunca existió, no hay historia que
     * reponer. `implante` es terminal en esta versión: si el implante fracasa
     * y hay que retirarlo y poner otro, eso se modela como una intervención
     * nueva y queda declarado como pendiente en `docs/10`.
     *
     * @var list<EstadoPieza>
     */
    private const TERMINALES = [
        EstadoPieza::Ausente,
        EstadoPieza::Implante,
    ];

    /**
     * Desde `extraida` solo se sale hacia un reemplazo.
     *
     * @var list<EstadoPieza>
     */
    private const DESDE_EXTRAIDA = [
        EstadoPieza::Implante,
        EstadoPieza::Protesis,
        EstadoPieza::Puente,
    ];

    /**
     * True si la transición está permitida.
     */
    public static function permite(EstadoPieza $anterior, EstadoPieza $nuevo): bool
    {
        // Quedarse en el mismo estado no es una transición: es un recargar.
        // Se permite, porque repetir un sellante o rehacer una obturación
        // sobre la misma pieza es algo normal.
        if ($anterior === $nuevo) {
            return true;
        }

        if (in_array($anterior, self::TERMINALES, true)) {
            return false;
        }

        if ($anterior === EstadoPieza::Extraida) {
            return in_array($nuevo, self::DESDE_EXTRAIDA, true);
        }

        if (in_array($nuevo, self::TERMINALES, true)) {
            // Se puede llegar a un terminal desde cualquier estado no terminal:
            // una pieza ausente puede estarlo desde el inicio (agenesia) y un
            // implante se puede colocar sobre una pieza sana que se perdió.
            return true;
        }

        return true;
    }

    /**
     * Explica por qué una transición está prohibida.
     *
     * El mensaje va directo a la pantalla del profesional. No es un código de
     * error: es la explicación de por qué el sistema no lo dejó hacer lo que
     * estaba intentando, que es justo lo que un odontólogo necesita para
     * entender que la pieza que quiere tratar no es la que cree.
     *
     * @param  int|null  $numeroPieza  número FDI, para que el mensaje nombre
     *                                 la pieza y no solo el estado.
     */
    public static function explica(EstadoPieza $anterior, EstadoPieza $nuevo, ?int $numeroPieza = null): string
    {
        $pieza = $numeroPieza === null ? 'La pieza' : sprintf('La pieza %d', $numeroPieza);

        if ($anterior === EstadoPieza::Ausente) {
            return sprintf(
                '%s está en estado "ausente": nunca estuvo presente en la boca del paciente. '
                . 'Si en realidad se extrajo, hay que registrar primero la extracción, que es un hecho '
                . 'distinto y queda en la cronología.',
                $pieza,
            );
        }

        if ($anterior === EstadoPieza::Extraida) {
            return sprintf(
                '%s está en estado "extraída". Desde ahí no se vuelve a un estado natural '
                . '(sano, obturado, con corona, endodoncia): la extracción es un hecho y queda en la '
                . 'cronología para siempre. Lo que corresponde es registrar un implante, una prótesis '
                . 'o un puente, que son hechos nuevos con fecha propia.',
                $pieza,
            );
        }

        if ($anterior === EstadoPieza::Implante) {
            return sprintf(
                '%s tiene un implante registrado. Un implante no se "cambia de estado": si fracasa '
                . 'y hay que retirarlo, es una intervención nueva que se registra como tal. '
                . 'Esta versión del sistema no modela esa intervención; queda anotado en docs/10.',
                $pieza,
            );
        }

        return sprintf(
            '%s no puede pasar de "%s" a "%s".',
            $pieza,
            $anterior->etiqueta(),
            $nuevo->etiqueta(),
        );
    }

    /**
     * Lanza la excepción si la transición no está permitida.
     *
     * @throws InvalidArgumentException
     */
    public static function exigir(EstadoPieza $anterior, EstadoPieza $nuevo, ?int $numeroPieza = null): void
    {
        if (! self::permite($anterior, $nuevo)) {
            throw new InvalidArgumentException(self::explica($anterior, $nuevo, $numeroPieza));
        }
    }
}