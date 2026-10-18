<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un plan de tratamiento.
 *
 * UN PLAN NO ES UNA ACCIÓN HECHA
 * -----------------------------
 * El plan es lo que se va a hacer. El odontograma y la cronología son lo que
 * se hizo. Por eso el plan tiene su propio ciclo de vida y no se mezcla con
 * las intervenciones: un plan puede estar en curso con la mitad de sus etapas
 * realizadas, y eso no dice nada sobre si las piezas se tocaron.
 *
 * Cada etapa tiene su propio estado, y el estado del plan se deriva de las
 * etapas, no al revés.
 */
enum EstadoPlan: string
{
    case Borrador = 'borrador';
    case Presentado = 'presentado';
    case Aprobado = 'aprobado';
    case EnCurso = 'en_curso';
    case Completado = 'completado';
    case Suspendido = 'suspendido';
    case Rechazado = 'rechazado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Presentado => 'Presentado al paciente',
            self::Aprobado => 'Aprobado por el paciente',
            self::EnCurso => 'En curso',
            self::Completado => 'Completado',
            self::Suspendido => 'Suspendido',
            self::Rechazado => 'Rechazado por el paciente',
        };
    }

    /**
     * Un plan aprobado o en curso ya obliga a algo. Un borrador no: es una
     * idea del profesional que todavía puede tirarse abajo sin consecuencias.
     */
    public function compromete(): bool
    {
        return in_array($this, [self::Aprobado, self::EnCurso, self::Completado], true);
    }

    /** Un plan terminado o cancelado ya no admite etapas nuevas. */
    public function admiteEtapas(): bool
    {
        return in_array($this, [self::Borrador, self::Presentado, self::Aprobado, self::EnCurso], true);
    }
}