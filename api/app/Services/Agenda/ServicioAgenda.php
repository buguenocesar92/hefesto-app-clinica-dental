<?php

declare(strict_types=1);

namespace App\Services\Agenda;

use App\Enums\EstadoCita;
use App\Models\Cita;
use App\Models\DesbloqueoAlergia;
use App\Models\Paciente;
use App\Models\Profesional;
use App\Models\Procedimiento;
use App\Services\Alergias\RevisorDeObstaclos;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Agenda: agendar una cita con todas las reglas juntas.
 *
 * EL ORDEN DE LAS COMPROBACIONES
 * -----------------------------
 * 1. Procedimiento y profesional existen.
 * 2. Alergias y condiciones. Si hay un obstáculo, se responde 422 con el
 *    detalle. Va PRIMERO, antes de la disponibilidad: si el paciente no se
 *    puede atender, no tiene sentido preguntarle al sistema si el sillón está
 *    libre.
 * 3. Disponibilidad del profesional.
 * 4. Se calcula `fecha_fin` con la duración del procedimiento y se escribe.
 *
 * La duración no es un parámetro de la cita: sale del procedimiento. Es la
 * respuesta a que no todos los procedimientos ocupan lo mismo.
 */
class ServicioAgenda
{
    public function __construct(
        private readonly RevisorDeObstaclos $revisor,
        private readonly VerificadorDeDisponibilidad $disponibilidad,
    ) {}

    /**
     * Agenda una cita.
     *
     * @param  array{ paciente: Paciente, profesional: Profesional, procedimiento: Procedimiento, fecha_inicio: DateTimeInterface|string, motivo?: string|null, notas?: string|null, etapa_plan_id?: string|null, plan_tratamiento_id?: string|null, silla?: string|null }  $datos
     *
     * @throws \DomainException si hay un allergy blocker o un choque de agenda.
     */
    public function agendar(array $datos): Cita
    {
        $paciente = $datos['paciente'];
        $profesional = $datos['profesional'];
        $procedimiento = $datos['procedimiento'];

        $inicio = $datos['fecha_inicio'] instanceof DateTimeInterface
            ? Carbon::instance($datos['fecha_inicio'])
            : Carbon::parse($datos['fecha_inicio']);

        // 2. Alergias. Este es el bloqueo del que habla el encargo, y va
        //    antes que todo lo demás porque es el único que no se resuelve
        //    buscas otro horario.
        $obstaclos = $this->revisor->obstaclosDe($paciente, $procedimiento, $inicio);

        if ($obstaclos !== []) {
            throw new \DomainException($this->revisor->mensajeDeBloqueo($obstaclos, $procedimiento));
        }

        // 3. Disponibilidad.
        $fin = $inicio->copy()->addMinutes($procedimiento->duracion_minutos);

        $conflictos = $this->disponibilidad->conflictos($profesional, $inicio, $fin);

        if ($conflictos !== []) {
            throw new \DomainException(implode(' ', $conflictos));
        }

        // 4. Escritura. La restricción de la base vuelve a comprobar el
        //    solapamiento: si dos recepcionistas guardan a la vez, una de las
        //    dos falla y no se pierde ninguna cita.
        return Cita::query()->create([
            'tenant_id' => (string) $paciente->tenant_id,
            'paciente_id' => $paciente->id,
            'profesional_id' => $profesional->id,
            'procedimiento_id' => $procedimiento->id,
            'plan_tratamiento_id' => $datos['plan_tratamiento_id'] ?? null,
            'etapa_plan_id' => $datos['etapa_plan_id'] ?? null,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'duracion_minutos' => $procedimiento->duracion_minutos,
            'estado' => EstadoCita::Agendada->value,
            'silla' => $datos['silla'] ?? null,
            'motivo' => $datos['motivo'] ?? null,
            'notas' => $datos['notas'] ?? null,
            'desbloqueo_alergia_id' => $this->desbloqueoQueSeAplica($paciente, $procedimiento, $inicio)?->id,
        ]);
    }

    /**
     * Desbloqueo vigente que cubre esta cita, si lo hay.
     *
     * Se guarda en la cita para que al mirar la agenda se vea de inmediato que
     * esa cita se agendó sobre una alergia con un desbloqueo registrado, y no
     * que simplemente no había ninguna.
     */
    private function desbloqueoQueSeAplica(
        Paciente $paciente,
        Procedimiento $procedimiento,
        Carbon $fecha,
    ): ?DesbloqueoAlergia {
        return $this->revisor->desbloqueosVigentes($paciente, $procedimiento, $fecha)->first();
    }
}