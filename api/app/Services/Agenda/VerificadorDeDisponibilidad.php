<?php

declare(strict_types=1);

namespace App\Services\Agenda;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Profesional;
use App\Enums\EstadoCita;
use Illuminate\Support\Carbon;

/**
 * Comprobación de disponibilidad de un profesional.
 *
 * POR QUÉ EXISTE, SI LA BASE YA LO PROHÍBE
 * -----------------------------------------
 * En `citas` hay una restricción `EXCLUDE USING gist` que impide el solapamiento
 * entre dos citas del mismo profesional. Esa restricción es la garantía real y
 * no se puede esquivar.
 *
 * Esta clase existe para el otro caso: las citas y los bloqueos de agenda
 * viven en tablas distintas, y PostgreSQL no puede escribir una exclusión
 * entre dos tablas. Un bloqueo de vacaciones y una cita pueden pisarse sin que
 * ninguna restricción lo note, así que hay que mirarlo antes.
 *
 * Además devuelve el conflicto con un mensaje que el recepcionista entienda.
 * La restricción, si salta, lanza una excepción de PostgreSQL que llega a la
 * pantalla como un error de SQL.
 */
class VerificadorDeDisponibilidad
{
    /**
     * Choques de una cita que se quiere agendar.
     *
     * @return list<string> líneas con el motivo del conflicto
     */
    public function conflictos(
        Profesional $profesional,
        Carbon $inicio,
        Carbon $fin,
        ?string $citaIdAModificar = null,
    ): array {
        $conflictoCitas = Cita::query()
            ->where('profesional_id', $profesional->id)
            ->whereIn('estado', array_map(
                static fn (EstadoCita $estado): string => $estado->value,
                array_filter(
                    EstadoCita::cases(),
                    static fn (EstadoCita $estado): bool => $estado->ocupaAgenda(),
                ),
            ))
            ->where('fecha_inicio', '<', $fin)
            ->where('fecha_fin', '>', $inicio)
            ->when($citaIdAModificar !== null, static fn ($consulta) => $consulta->whereKeyNot($citaIdAModificar))
            ->orderBy('fecha_inicio')
            ->get();

        $lineas = [];

        foreach ($conflictoCitas as $cita) {
            $lineas[] = sprintf(
                'El profesional ya tiene una cita de %s a %s (%s).',
                $cita->fecha_inicio->format('d/m/Y H:i'),
                $cita->fecha_fin->format('H:i'),
                $cita->paciente?->nombreCompleto() ?? 'paciente sin nombre',
            );
        }

        $conflictosBloqueos = BloqueoAgenda::query()
            ->where('profesional_id', $profesional->id)
            ->where('fecha_inicio', '<', $fin)
            ->where('fecha_fin', '>', $inicio)
            ->orderBy('fecha_inicio')
            ->get();

        foreach ($conflictosBloqueos as $bloqueo) {
            $lineas[] = sprintf(
                'El profesional tiene un bloqueo de agenda de %s a %s (%s).',
                $bloqueo->fecha_inicio->format('d/m/Y H:i'),
                $bloqueo->fecha_fin->format('H:i'),
                $bloqueo->motivo,
            );
        }

        return $lineas;
    }

    /** True si el tramo está libre. */
    public function estaLibre(
        Profesional $profesional,
        Carbon $inicio,
        Carbon $fin,
        ?string $citaIdAModificar = null,
    ): bool {
        return $this->conflictos($profesional, $inicio, $fin, $citaIdAModificar) === [];
    }
}