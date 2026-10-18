<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una etapa dentro de un plan de tratamiento.
 *
 * La distinción importante es `Pendiente` contra `Programada`: lo pendiente no
 * tiene fecha, lo programado sí. Y `Realizada` significa que se hizo, lo que
 * normalmente va acompañado de una intervención en la cronología de la pieza,
 * pero no obliga a ella: hay etapas administrativas (un examen, una derivación
 * a endodoncista) que no tocan ninguna pieza.
 */
enum EstadoEtapa: string
{
    case Pendiente = 'pendiente';
    case Programada = 'programada';
    case EnCurso = 'en_curso';
    case Realizada = 'realizada';
    case Omitida = 'omitida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Programada => 'Programada',
            self::EnCurso => 'En curso',
            self::Realizada => 'Realizada',
            self::Omitida => 'Omitida',
        };
    }

    /** La etapa ya es un hecho, no una intención. */
    public function esHecho(): bool
    {
        return in_array($this, [self::Realizada, self::Omitida], true);
    }

    /** La etapa sigue abierta: ocupa agenda. */
    public function estaAbierta(): bool
    {
        return in_array($this, [self::Pendiente, self::Programada, self::EnCurso], true);
    }
}