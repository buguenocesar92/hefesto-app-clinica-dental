<?php

declare(strict_types=1);

namespace App\Services\Odontograma;

/**
 * Catálogo de piezas dentarias en notación FDI.
 *
 * NO INVENTADO: es el estándar ISO 3950, "Odontología — Sistema de
 * designación de dientes y regiones de la cavidad bucal", desarrollado por la
 * Federación Dental Mundial (FDI). Fuente consultada al escribir este
 * código: <https://en.wikipedia.org/wiki/FDI_World_Dental_Federation_notation>,
 * que cita la norma ISO 3950:2016 en <https://www.iso.org/standard/68292.html>.
 * Copia del contenido en `docs/02-MODELO-DE-DATOS.md`, sección 3.
 *
 * CÓMO FUNCIONA
 * -------------
 * Dos dígitos. El primero es el cuadrante, el segundo la pieza dentro del
 * cuadrante. El cuadrante se cuenta **en sentido horario desde la vista del
 * dentista** (que es la vista opuesta a la del paciente):
 *
 *   1 = superior derecho    5 = superior derecho deciduo
 *   2 = superior izquierdo  6 = superior izquierdo deciduo
 *   3 = inferior izquierdo  7 = inferior izquierdo deciduo
 *   4 = inferior derecho    8 = inferior derecho deciduo
 *
 * La pieza se cuenta desde la línea media hacia atrás:
 *
 *   1 incisivo central   4 primer premolar   6 primer molar
 *   2 incisivo lateral   5 segundo premolar  7 segundo molar
 *   3 canino             8 tercer molar
 *
 * ALCANCE DE ESTA VERSION
 * -----------------------
 * Solo dentición permanente (11 a 48). La deciduo usa los mismos cuadrantes
 * pero con letras de la A a la E, y no está modelada. Queda declarado como
 * pendiente en `docs/10-PRUEBAS-Y-ESTADO.md`: agregar la dentición deciduo
 * exige decidir cómo se relaciona con la definitiva (una misma pieza cambia
 * de código al erupcionar), y esa decisión no se ha tomado.
 */
final class PiezaFdi
{
    /**
     * Las 32 piezas permanentes, en el orden en que se dibuja el mapa.
     *
     * Orden de dibujo: el odontograma se ve como el odontólogo lo ve, es
     * decir, la derecha del paciente queda a la izquierda de la pantalla.
     * Filas: arriba y abajo. Dentro de cada fila, de izquierda a derecha.
     */
    public const DENTICION_PERMANENTE = [
        // Superior derecho (cuadrante 1), de la pieza más posterior a la línea media.
        18, 17, 16, 15, 14, 13, 12, 11,
        // Superior izquierdo (cuadrante 2).
        21, 22, 23, 24, 25, 26, 27, 28,
        // Inferior derecho (cuadrante 4). Va antes que el 3 porque la fila
        // inferior se dibuja al revés que la superior.
        48, 47, 46, 45, 44, 43, 42, 41,
        // Inferior izquierdo (cuadrante 3).
        31, 32, 33, 34, 35, 36, 37, 38,
    ];

    /**
     * Cuadrantes de la dentición permanente.
     *
     * @var list<int>
     */
    public const CUADRANTES_PERMANENTES = [1, 2, 3, 4];

    /**
     * Cuadrantes de la dentición decidua, que esta versión no usa.
     *
     * @var list<int>
     */
    public const CUADRANTES_DECIDUOS = [5, 6, 7, 8];

    /**
     * Nombre de la pieza a partir del quadrante y el número, en español de
     * Chile. Se usa en la etiqueta de la pantalla y en los documentos
     * imprimibles, donde el número solo no le dice nada a quien lo lee.
     *
     * @var array<string, string>
     */
    private const NOMBRES_ARCADA_SUPERIOR = [
        '11' => 'incisivo central superior derecho',
        '12' => 'incisivo lateral superior derecho',
        '13' => 'canino superior derecho',
        '14' => 'primer premolar superior derecho',
        '15' => 'segundo premolar superior derecho',
        '16' => 'primer molar superior derecho',
        '17' => 'segundo molar superior derecho',
        '18' => 'tercer molar superior derecho',
        '21' => 'incisivo central superior izquierdo',
        '22' => 'incisivo lateral superior izquierdo',
        '23' => 'canino superior izquierdo',
        '24' => 'primer premolar superior izquierdo',
        '25' => 'segundo premolar superior izquierdo',
        '26' => 'primer molar superior izquierdo',
        '27' => 'segundo molar superior izquierdo',
        '28' => 'tercer molar superior izquierdo',
    ];

    /** @var array<string, string> */
    private const NOMBRES_ARCADA_INFERIOR = [
        '41' => 'incisivo central inferior derecho',
        '42' => 'incisivo lateral inferior derecho',
        '43' => 'canino inferior derecho',
        '44' => 'primer premolar inferior derecho',
        '45' => 'segundo premolar inferior derecho',
        '46' => 'primer molar inferior derecho',
        '47' => 'segundo molar inferior derecho',
        '48' => 'tercer molar inferior derecho',
        '31' => 'incisivo central inferior izquierdo',
        '32' => 'incisivo lateral inferior izquierdo',
        '33' => 'canino inferior izquierdo',
        '34' => 'primer premolar inferior izquierdo',
        '35' => 'segundo premolar inferior izquierdo',
        '36' => 'primer molar inferior izquierdo',
        '37' => 'segundo molar inferior izquierdo',
        '38' => 'tercer molar inferior izquierdo',
    ];

    /** Filas del mapa: superior e inferior. @var list<list<int>> */
    public const FILAS = [
        [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28],
        [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38],
    ];

    /** True si el número corresponde a una pieza permanente válida. */
    public static function esValido(int $numero): bool
    {
        if ($numero < 11 || $numero > 48) {
            return false;
        }

        $cuadrante = intdiv($numero, 10);
        $pieza = $numero % 10;

        return in_array($cuadrante, self::CUADRANTES_PERMANENTES, true)
            && $pieza >= 1
            && $pieza <= 8;
    }

    /**
     * Lista de todos los números válidos, en orden.
     *
     * @return list<int>
     */
    public static function todos(): array
    {
        return self::DENTICION_PERMANENTE;
    }

    /** Cuadrante (primer dígito) de una pieza. */
    public static function cuadrante(int $numero): int
    {
        return intdiv($numero, 10);
    }

    /** Posición dentro del cuadrante (segundo dígito). */
    public static function posicion(int $numero): int
    {
        return $numero % 10;
    }

    /**
     * Arcada: superior o inferior.
     */
    public static function arcada(int $numero): string
    {
        return self::cuadrante($numero) <= 2 ? 'superior' : 'inferior';
    }

    /**
     * Lado del paciente: derecho o izquierdo.
     *
     * Ojo con el giro: el cuadrante 1 es superior **derecho**, y el mapa se
     * dibuja al revés que el paciente. Este método devuelve el lado del
     * paciente, que es el que aparece en los documentos para el paciente.
     */
    public static function lado(int $numero): string
    {
        return in_array(self::cuadrante($numero), [1, 4], true) ? 'derecho' : 'izquierdo';
    }

    /**
     * Nombre descriptivo de la pieza, para las etiquetas y los impresos.
     */
    public static function nombre(int $numero): string
    {
        $texto = (string) $numero;

        return self::NOMBRES_ARCADA_SUPERIOR[$texto]
            ?? self::NOMBRES_ARCADA_INFERIOR[$texto]
            ?? 'pieza ' . $texto;
    }

    /**
     * Columna que ocupa la pieza en el mapa, contando desde la izquierda
     * de la fila. La interfaz la usa para ubicar el dibujo.
     */
    public static function columna(int $numero): int
    {
        $indice = array_search($numero, self::FILAS[0], true);

        if ($indice !== false) {
            return $indice + 1;
        }

        $indice = array_search($numero, self::FILAS[1], true);

        return $indice === false ? 0 : $indice + 1;
    }

    /**
     * Fila del mapa: 1 arriba (superior), 2 abajo (inferior).
     */
    public static function fila(int $numero): int
    {
        return self::arcada($numero) === 'superior' ? 1 : 2;
    }

    /**
     * Formatea el número con el punto opcional de la norma.
     *
     * La norma admite escribir `1.1` para que no se confunda con el sistema
     * universal de numeración de Estados Unidos. La aplicación guarda siempre
     * el número sin punto (entero) y lo muestra sin punto; el punto queda
     * documentado por si el consultorio lo pide.
     */
    public static function formatear(int $numero, bool $conPunto = false): string
    {
        return $conPunto
            ? intdiv($numero, 10) . '.' . ($numero % 10)
            : (string) $numero;
    }
}