<?php

declare(strict_types=1);

namespace App\Services\Alergias;

use App\Models\Alergia;
use App\Models\Condicion;
use App\Models\DesbloqueoAlergia;
use App\Models\Paciente;
use App\Models\Procedimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Revisión de los obstáculos que impiden agendar.
 *
 * LA DECISIÓN: UNA ALERGIA ACTIVA BLOQUEA, NO AVISA
 * -------------------------------------------------
 * La alternativa era mostrarla como una nota en la ficha, y se descartó.
 * Una nota se lee una vez y después deja de leerse: la pantalla está llena,
 * la consulta apura y el caso grave es el raro. En odontología, una reacción
 * anafiláctica a un anestésico local o al látex no es una molestia: es una
 * muerte. Un sistema que deja ese dato en una nota está entregando una falsa
 * sensación de seguridad.
 *
 * Con un bloqueo, en cambio, no hay forma de agendar sin pasar por él. Es
 * molesto, y esa molestia es el punto: obliga a la conversación que tiene que
 * tener lugar.
 *
 * QUÉ SE BLOQUEA
 * --------------
 * - Toda alergia `activa`, sin excepción y sin importar el procedimiento.
 * - Toda condición `activa` con `bloquea_atencion` marcada por el
 *   profesional.
 *
 * No hay una lista de procedimientos "seguros" que salten el control. Una
 * lista así sería una puerta trasera escrita en el sistema, y alguien la
 * usaría sin pensar.
 *
 * CÓMO SE PASA
 * ------------
 * Con un desbloqueo registrado en `desbloqueos_alergia`: qué profesional lo
 * concede, sobre qué alergia, con qué motivo y hasta cuándo. Si un
 * desbloqueo no tiene profesional y motivo, no es un desbloqueo. La razón de
 * que exista esta tabla en vez de una bandera está en la migración
 * `2026_02_10_000010_crear_tabla_desbloqueos_alergia.php`.
 */
class RevisorDeObstaclos
{
    /**
     * Obstáculos que bloquean al paciente para un procedimiento.
     *
     * @return list<ObstacloAtencion>
     */
    public function obstaclosDe(Paciente $paciente, Procedimiento $procedimiento, ?Carbon $fecha = null): array
    {
        $dia = $fecha ?? Carbon::now();
        $desbloqueos = $this->desbloqueosVigentes($paciente, $procedimiento, $dia);

        $obstaclos = [];

        foreach ($this->alergiasVigentes($paciente) as $alergia) {
            if ($this->estaCubierta($desbloqueos, $alergia->id, null, $dia)) {
                continue;
            }

            $obstaclos[] = ObstacloAtencion::deAlergia($alergia);
        }

        foreach ($this->condicionesBloqueantes($paciente) as $condicion) {
            if ($this->estaCubierta($desbloqueos, null, $condicion->id, $dia)) {
                continue;
            }

            $obstaclos[] = ObstacloAtencion::deCondicion($condicion);
        }

        return $obstaclos;
    }

    /**
     * True si el paciente NO tiene ningún obstáculo vigente.
     */
    public function puedeAgendar(Paciente $paciente, Procedimiento $procedimiento, ?Carbon $fecha = null): bool
    {
        return $this->obstaclosDe($paciente, $procedimiento, $fecha) === [];
    }

    /**
     * Desbloqueos que hoy habilitan a este paciente para este procedimiento.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DesbloqueoAlergia>
     */
    public function desbloqueosVigentes(Paciente $paciente, Procedimiento $procedimiento, Carbon $dia)
    {
        return $paciente->desbloqueos()
            ->where('concedido', '<=', $dia->endOfDay())
            ->get()
            ->filter(function (DesbloqueoAlergia $desbloqueo) use ($procedimiento, $dia): bool {
                if (! $desbloqueo->vigenteEn($dia->toDateString())) {
                    return false;
                }

                // Un desbloqueo acotado a un procedimiento no habilita otro.
                // El desbloqueo general (procedimiento_id en blanco) sí.
                if ($desbloqueo->procedimiento_id === null) {
                    return true;
                }

                return $desbloqueo->procedimiento_id === $procedimiento->id;
            })
            ->values();
    }

    /**
     * Texto para mostrar cuando el agendamiento se rechaza.
     */
    public function mensajeDeBloqueo(array $obstaclos, Procedimiento $procedimiento): string
    {
        $lineas = array_map(
            static fn (ObstacloAtencion $obstaclo): string => '- ' . $obstaclo->descripcion(),
            $obstaclos,
        );

        return sprintf(
            'No se puede agendar "%s": el paciente tiene %d antecedente(s) que bloquean la atención. %s '
            . 'Para agendar, un profesional debe registrar un desbloqueo con su motivo.',
            $procedimiento->nombre,
            count($obstaclos),
            implode(' ', $lineas),
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Alergia>
     */
    private function alergiasVigentes(Paciente $paciente)
    {
        return $paciente->alergias()
            ->where('activa', true)
            ->get()
            ->sortByDesc(fn (Alergia $alergia): int => $alergia->severidad->urgencia())
            ->values();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Condicion>
     */
    private function condicionesBloqueantes(Paciente $paciente)
    {
        return $paciente->condiciones()
            ->where('activa', true)
            ->where('bloquea_atencion', true)
            ->get();
    }

    private function estaCubierta(
        Collection $desbloqueos,
        ?string $alergiaId,
        ?string $condicionId,
        Carbon $dia,
    ): bool {
        foreach ($desbloqueos as $desbloqueo) {
            if (! $desbloqueo->vigenteEn($dia->toDateString())) {
                continue;
            }

            if ($alergiaId !== null && $desbloqueo->alergia_id === $alergiaId) {
                return true;
            }

            if ($condicionId !== null && $desbloqueo->condicion_id === $condicionId) {
                return true;
            }
        }

        return false;
    }
}