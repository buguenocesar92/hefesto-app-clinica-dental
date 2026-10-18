<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado actual de una pieza dentaria.
 *
 * DECISION CENTRAL DEL MODELO
 * --------------------------
 * Este enum es **el estado, no la historia**. Es lo que se lee hoy en la
 * pantalla del odontograma. La cronología vive en otra tabla,
 * `intervenciones_pieza`, y es acumulativa: nunca se sobrescribe.
 *
 * Un cambio de estado aqui SIEMPRE va acompañado de una fila nueva en la
 * cronología, dentro de la misma transacción, en
 * `App\Services\Odontograma\ServicioOdontograma`. Si alguien escribe en
 * `piezas` sin escribir la intervención, el disparador de la migración
 * `2026_02_10_000021_proteger_cronologia_piezas.php` lo rechaza.
 *
 * POR QUE `ausente` Y `extraida` SON ESTADOS DISTINTOS
 * ---------------------------------------------------
 * `ausente` es una pieza que nunca existió (agenesia, pieza no eruptada en
 * la edad esperada, extracción de un diente deciduo que no es la misma pieza).
 * `extraida` es una pieza que existió y se perdió. Un diente ausente por
 * agenesia no se puede "reponer" con un implante porque nunca estuvo ahí, y
 * un diente extraído sí. Mezclarlos destruiría la información de que la pieza
 * estuvo alguna vez en la boca del paciente.
 */
enum EstadoPieza: string
{
    case Sano = 'sano';
    case Ausente = 'ausente';
    case Caries = 'caries';
    case Obturado = 'obturado';
    case Corona = 'corona';
    case Endodoncia = 'endodoncia';
    case Sellante = 'sellante';
    case Fractura = 'fractura';
    case IndicacionExtraccion = 'indicacion_extraccion';
    case PorExtraer = 'por_extraer';
    case Extraida = 'extraida';
    case Implante = 'implante';
    case Protesis = 'protesis';
    case Puente = 'puente';

    /**
     * Etiqueta corta para el mapa del odontograma.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Sano => 'Sano',
            self::Ausente => 'Ausente',
            self::Caries => 'Caries',
            self::Obturado => 'Obturado',
            self::Corona => 'Corona',
            self::Endodoncia => 'Endodoncia',
            self::Sellante => 'Sellante',
            self::Fractura => 'Fractura',
            self::IndicacionExtraccion => 'Indicación de extracción',
            self::PorExtraer => 'Por extraer',
            self::Extraida => 'Extraída',
            self::Implante => 'Implante',
            self::Protesis => 'Prótesis',
            self::Puente => 'Puente',
        };
    }

    /**
     * Abreviatura de una o dos letras, como se dibuja dentro de la pieza.
     *
     * Es la convencion de papel que el consultorio ya usa en su cuaderno:
     * la letra va dentro del dibujo de la pieza. El color lo aplica la
     * interfaz, no el backend.
     */
    public function abreviatura(): string
    {
        return match ($this) {
            self::Sano => 'S',
            self::Ausente => 'A',
            self::Caries => 'C',
            self::Obturado => 'O',
            self::Corona => 'CR',
            self::Endodoncia => 'E',
            self::Sellante => 'SE',
            self::Fractura => 'F',
            self::IndicacionExtraccion => 'IX',
            self::PorExtraer => 'PX',
            self::Extraida => 'X',
            self::Implante => 'IM',
            self::Protesis => 'PR',
            self::Puente => 'PU',
        };
    }

    /**
     * True si la pieza ya no está en la boca del paciente.
     *
     * `ausente` cuenta: no está. Lo que cambia es el motivo, y el motivo se
     * lee en la cronología, no acá.
     */
    public function estaFueraDeLaBoca(): bool
    {
        return in_array($this, [self::Ausente, self::Extraida], true);
    }

    /**
     * True si el estado representa un remplacement protésico o un implante.
     *
     * Son los unicos estados a los que se puede llegar **desde** `extraida`.
     * Ver `MaquinaDeEstadosPieza`.
     */
    public function esReemplazo(): bool
    {
        return in_array($this, [self::Implante, self::Protesis, self::Puente], true);
    }

    /**
     * Grupo de color para la interfaz. El backend no manda color: manda
     * categoria, y la interfaz decide el tono. Asi el mismo dato sirve para
     * pantalla, impresion en blanco y negro y lectura para daltónicos.
     */
    public function grupo(): string
    {
        return match ($this) {
            self::Sano => 'sano',
            self::Ausente, self::Extraida => 'fuera',
            self::Caries, self::Fractura => 'problema',
            self::Obturado, self::Corona, self::Endodoncia, self::Sellante => 'tratado',
            self::IndicacionExtraccion, self::PorExtraer => 'pendiente',
            self::Implante, self::Protesis, self::Puente => 'reemplazo',
        };
    }
}