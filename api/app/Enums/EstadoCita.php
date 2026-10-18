<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una cita de la agenda clínica.
 *
 * `Atendido` y `NoAsistio` son los dos finales reales: la cita terminó con
 * el paciente dentro o no. Entre medias está `Confirmada`, que es la que
 * obliga a revisar alergias antes del día.
 */
enum EstadoCita: string
{
    case Agendada = 'agendada';
    case Confirmada = 'confirmada';
    case Atendida = 'atendida';
    case NoAsistio = 'no_asistio';
    case Anulada = 'anulada';
    case Bloqueada = 'bloqueada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Agendada => 'Agendada',
            self::Confirmada => 'Confirmada',
            self::Atendida => 'Atendida',
            self::NoAsistio => 'No asistió',
            self::Anulada => 'Anulada',
            self::Bloqueada => 'Bloqueada',
        };
    }

    /**
     * La cita ocupa la agenda del profesional y no se puede agendar otra en
     * ese mismo tramo.
     */
    public function ocupaAgenda(): bool
    {
        return ! in_array($this, [self::Anulada, self::NoAsistio], true);
    }

    /** La cita ya pasó y produjo un hecho clínico. */
    public function esHecho(): bool
    {
        return $this === self::Atendida;
    }
}