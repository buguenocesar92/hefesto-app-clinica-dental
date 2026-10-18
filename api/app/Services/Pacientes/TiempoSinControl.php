<?php

declare(strict_types=1);

namespace App\Services\Pacientes;

use App\Models\Paciente;
use App\Models\IntervencionPieza;
use App\Models\Cita;
use App\Enums\EstadoCita;
use Illuminate\Support\Carbon;

/**
 * Cuánto tiempo lleva un paciente sin control.
 *
 * POR QUÉ NO ES UNA COLUMNA
 * -------------------------
 * "Última atención: 12/03/2024" en una fila de la tabla de pacientes es un
 * dato que se desactualiza solo. Nadie se avisa de que dejó de actualizarse,
 * y un consultorio entero puede mostrar meses que ya no son ciertos.
 *
 * Acá se calcula, siempre, sobre las dos fuentes que sí están al día:
 *
 *  1. La última cita en estado `atendida`.
 *  2. La última intervención registrada en la cronología.
 *
 * Se toma la más reciente de las dos, porque una puede ir más atrás que la
 * otra: puede haber seis meses desde la última cita y una intervención
 * registrada ayer al migrar un cuaderno. Lo que importa para el control es el
 * último contacto real con el consultorio.
 *
 * Devuelve null cuando el paciente no tiene ninguna atención registrada: no
 * es lo mismo "hace cero días" que "no hay dato". En pantalla, ese null es
 * "paciente nuevo".
 */
class TiempoSinControl
{
    public function __construct(private readonly Paciente $paciente) {}

    /**
     * Fecha del último contacto clínico registrado, o null si no hay ninguno.
     */
    public function ultimaAtencion(): ?Carbon
    {
        $cita = $this->ultimaCitaAtendida();
        $intervencion = $this->ultimaIntervencion();

        if ($cita === null && $intervencion === null) {
            return null;
        }

        if ($cita === null) {
            return $intervencion;
        }

        if ($intervencion === null) {
            return $cita;
        }

        return $cita->greaterThanOrEqualTo($intervencion) ? $cita : $intervencion;
    }

    /**
     * Días desde el último control, o null si no hay registro.
     */
    public function dias(): ?int
    {
        $ultima = $this->ultimaAtencion();

        return $ultima?->diffInDays(now());
    }

    /**
     * Texto listo para la lista de pacientes.
     *
     * El texto distingue el caso sin dato del caso con atraso: son dos
     * situaciones que piden acciones distintas.
     */
    public function descripcion(): string
    {
        $dias = $this->dias();

        if ($dias === null) {
            return 'Sin atenciones registradas';
        }

        if ($dias === 0) {
            return 'Atendido hoy';
        }

        if ($dias < 0) {
            return 'Atención registrada con fecha futura: revisar';
        }

        return sprintf('Última atención hace %s', $this->enPalabras($dias));
    }

    private function ultimaCitaAtendida(): ?Carbon
    {
        $cita = Cita::query()
            ->where('paciente_id', $this->paciente->id)
            ->where('estado', EstadoCita::Atendida->value)
            ->orderByDesc('fecha_inicio')
            ->first();

        return $cita?->fecha_inicio;
    }

    private function ultimaIntervencion(): ?Carbon
    {
        $intervencion = IntervencionPieza::query()
            ->where('paciente_id', $this->paciente->id)
            ->orderByDesc('fecha')
            ->first();

        return $intervencion?->fecha;
    }

    /**
     * Días en palabras, que es como lo lee una persona y no una pantalla.
     */
    private function enPalabras(int $dias): string
    {
        if ($dias < 31) {
            return sprintf('%d día%s', $dias, $dias === 1 ? '' : 's');
        }

        if ($dias < 366) {
            $meses = (int) round($dias / 30);

            return sprintf('%d mes%s', $meses, $meses === 1 ? '' : 'es');
        }

        $anios = (int) round($dias / 365);

        return sprintf('%d año%s', $anios, $anios === 1 ? '' : 's');
    }
}