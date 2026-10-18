<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agenda;

use App\Enums\EstadoCita;
use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Paciente;
use App\Services\Agenda\ServicioAgenda;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alta, reprogramacion y anulacion de citas.
 *
 * ESTA CLASE NO ESCRIBE CITAS: DELega EN EL SERVICIO
 * --------------------------------------------------
 * Todo el trabajo de verdad esta en `App\Services\Agenda\ServicioAgenda`, que
 * es el unico lugar donde se decide si una atencion se puede agendar. El
 * controlador traduce HTTP a ese servicio y devuelve JSON.
 *
 * La razon de que sea asi es el orden de las comprobaciones. En un consultorio
 * el orden importa: primero se pregunta por la alergia, porque es el unico
 * bloqueo que no se resuelve buscando otro horario, y despues se pregunta por
 * el choque de agenda. Si el controlador validara disponibilidad antes de
 * alergias, reception veria "ese horario no sirve" y lo intentaria con otro,
 * sin enterarse nunca de que el problema era otro.
 *
 * LA DURACION NUNCA VIENE DEL CLIENTE
 * -----------------------------------
 * Sale de `procedimientos.duracion_minutos`. Un cliente que mandara su propia
 * duracion podria solapar citas escribiendo en `piezas` lo que quiera. Ver
 * ServicioAgenda::agendar.
 *
 * `DELETE` ES ANULAR, NO BORRAR. Ver CitaPolicy.
 */
class CitaController extends Controller
{
    public function __construct(private readonly ServicioAgenda $agenda) {}

    /**
     * Citas de un rango, para la pantalla de agenda.
     *
     * Los tres roles entran y ven lo mismo: nombre, hora, profesional,
     * procedimiento y estado. Eso es dato de agenda, no dato de salud. Ver
     * docs/03.
     */
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Cita::class);

        $datos = $peticion->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'profesional_id' => ['nullable', 'uuid'],
            'paciente_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', 'string', 'max:16'],
        ]);

        $desde = isset($datos['desde'])
            ? Carbon::parse($datos['desde'])->startOfDay()
            : Carbon::now()->startOfDay();

        $hasta = isset($datos['hasta'])
            ? Carbon::parse($datos['hasta'])->endOfDay()
            : $desde->copy()->addDay();

        $anticipacion = (int) config('clinica-dental.agenda.anticipacion_maxima_dias', 180);

        if ($desde->diffInDays(Carbon::now(), false) > $anticipacion) {
            return $this->error(
                "No se puede pedir agenda con mas de {$anticipacion} dias de anticipacion."
            );
        }

        $consultorioId = $this->consultorioId();

        $citas = Cita::query()
            ->with([
                'paciente:id,tenant_id,nombres,apellidos',
                'profesional:id,tenant_id,nombre,color_agenda',
                'procedimiento:id,tenant_id,codigo,nombre,duracion_minutos',
            ])
            ->where('tenant_id', $consultorioId)
            ->whereBetween('fecha_inicio', [$desde, $hasta])
            ->when(isset($datos['profesional_id']), fn ($c) => $c->where('profesional_id', $datos['profesional_id']))
            ->when(isset($datos['paciente_id']), fn ($c) => $c->where('paciente_id', $datos['paciente_id']))
            ->when(isset($datos['estado']), fn ($c) => $c->where('estado', $datos['estado']))
            ->orderBy('fecha_inicio')
            ->limit(500)
            ->get();

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'total' => $citas->count(),
            'citas' => $citas->map(fn (Cita $cita): array => $this->fila($cita))->all(),
        ]);
    }

    public function show(Cita $cita): JsonResponse
    {
        $this->authorize('view', $cita);

        $cita->load([
            'paciente:id,tenant_id,nombres,apellidos,telefono',
            'profesional:id,tenant_id,nombre',
            'procedimiento:id,tenant_id,codigo,nombre,duracion_minutos',
            'rebloqueo:id,tenant_id,motivo,concedido,vigente_hasta',
        ]);

        return response()->json(['cita' => $this->fila($cita, completo: true)]);
    }

    /**
     * Agendar.
     *
     * El paciente, el profesional y el procedimiento se resuelven DENTRO del
     * consultorio, por identificador y por `tenant_id`. Si se resolvieran solo
     * por clave primaria, un id adivinado de otro consultorio pasaria el chequeo
     * de la RLS al escribir la cita (que declara el tenant del paciente) y
     * dejaria una cita apuntando a un profesional de otro consultorio.
     */
    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Cita::class);

        $datos = $peticion->validate([
            'paciente_id' => ['required', 'uuid'],
            'profesional_id' => ['required', 'uuid'],
            'procedimiento_id' => ['required', 'uuid'],
            'fecha_inicio' => ['required', 'date'],
            'plan_tratamiento_id' => ['nullable', 'uuid'],
            'etapa_plan_id' => ['nullable', 'uuid'],
            'silla' => ['nullable', 'string', 'max:24'],
            'motivo' => ['nullable', 'string', 'max:240'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $consultorioId = $this->consultorioId();

        $paciente = Paciente::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($datos['paciente_id'])
            ->where('activo', true)
            ->first();

        if ($paciente === null) {
            return $this->error('El paciente no existe o esta inactivo en este consultorio.');
        }

        $profesional = Profesional::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($datos['profesional_id'])
            ->where('activo', true)
            ->first();

        if ($profesional === null) {
            return $this->error('El profesional no existe o esta inactivo en este consultorio.');
        }

        $procedimiento = Procedimiento::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($datos['procedimiento_id'])
            ->where('activo', true)
            ->first();

        if ($procedimiento === null) {
            return $this->error('El procedimiento no existe o esta inactivo en este consultorio.');
        }

        try {
            $cita = $this->agenda->agendar([
                'paciente' => $paciente,
                'profesional' => $profesional,
                'procedimiento' => $procedimiento,
                'fecha_inicio' => Carbon::parse($datos['fecha_inicio']),
                'motivo' => $datos['motivo'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'plan_tratamiento_id' => $datos['plan_tratamiento_id'] ?? null,
                'etapa_plan_id' => $datos['etapa_plan_id'] ?? null,
                'silla' => $datos['silla'] ?? null,
            ]);
        } catch (DomainException $e) {
            // El bloqueo por alergia y el choque de agenda llegan aqui como
            // `DomainException`, y el esqueleto los traduce a 422. El mensaje
            // lo escribe el servicio: es el unico que sabe por que no se puede.
            return $this->error($e->getMessage());
        }

        $cita->load(['paciente:id,tenant_id,nombres,apellidos', 'profesional:id,tenant_id,nombre', 'procedimiento:id,tenant_id,nombre']);

        return response()->json(['cita' => $this->fila($cita)], 201);
    }

    /**
     * Reprogramar una cita.
     *
     * Cancelar y volver a agendar seria lo mismo, pero dejaria dos filas y
     * perderia la cita original. Se mueve la misma fila, que es lo que el
     * consultorio espera ver en su historial.
     *
     * No se puede reprogramar una cita ya ocurrida: el desenlace ya se
     * escribio y mover la fecha despues seria reescribir historia clinica.
     */
    public function update(Request $peticion, Cita $cita): JsonResponse
    {
        $this->authorize('update', $cita);

        if ($cita->estado->esHecho()) {
            return $this->error(
                'La cita ya tiene un desenlace registrado. No se puede reprogramar.'
            );
        }

        $datos = $peticion->validate([
            'fecha_inicio' => ['required', 'date'],
            'profesional_id' => ['nullable', 'uuid'],
            'procedimiento_id' => ['nullable', 'uuid'],
            'silla' => ['nullable', 'string', 'max:24'],
            'motivo' => ['nullable', 'string', 'max:240'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $consultorioId = $this->consultorioId();

        $paciente = $cita->paciente()->first();

        if ($paciente === null) {
            return $this->error('El paciente de la cita no existe en este consultorio.');
        }

        $profesionalId = $datos['profesional_id'] ?? $cita->profesional_id;

        $profesional = Profesional::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($profesionalId)
            ->where('activo', true)
            ->first();

        if ($profesional === null) {
            return $this->error('El profesional no existe o esta inactivo en este consultorio.');
        }

        $procedimientoId = $datos['procedimiento_id'] ?? $cita->procedimiento_id;

        $procedimiento = Procedimiento::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($procedimientoId)
            ->where('activo', true)
            ->first();

        if ($procedimiento === null) {
            return $this->error('El procedimiento no existe o esta inactivo en este consultorio.');
        }

        // Al reprogramar se vuelve a pasar por el servicio completo, alergias
        // incluidas. Puede que el paciente tuviera una alergia registrada la
        // ultima vez y ahora no se pueda hacer: por eso no es un simple
        // `UPDATE` con una comprobacion de horario.
        //
        // La cita original se anula antes de agendar la nueva: si la nueva
        // falla, la original queda como estaba. Es lo contrario de lo que
        // haria un `UPDATE` directo, y es a proposito.
        $motivoAnulacion = 'Reprogramada el ' . now()->toDateString() . '.';

        $anteriorEstado = $cita->estado;
        $anteriorMotivo = $cita->motivo;

        $cita->forceFill([
            'estado' => EstadoCita::Anulada,
            'motivo' => $motivoAnulacion,
        ])->save();

        try {
            $nueva = $this->agenda->agendar([
                'paciente' => $paciente,
                'profesional' => $profesional,
                'procedimiento' => $procedimiento,
                'fecha_inicio' => Carbon::parse($datos['fecha_inicio']),
                'motivo' => $datos['motivo'] ?? $anteriorMotivo,
                'notas' => $datos['notas'] ?? $cita->notas,
                'silla' => $datos['silla'] ?? $cita->silla,
                'plan_tratamiento_id' => $cita->plan_tratamiento_id,
                'etapa_plan_id' => $cita->etapa_plan_id,
            ]);
        } catch (DomainException $e) {
            // Se devuelve la cita original a su estado. Todo dentro de la misma
            // peticion: la de la base nunca quedo anulada a ojos de nadie.
            $cita->forceFill([
                'estado' => $anteriorEstado,
                'motivo' => $anteriorMotivo,
            ])->save();

            return $this->error($e->getMessage());
        }

        $nueva->load(['paciente:id,tenant_id,nombres,apellidos', 'profesional:id,tenant_id,nombre', 'procedimiento:id,tenant_id,nombre']);

        return response()->json([
            'mensaje' => 'Cita reprogramada.',
            'cita_anulada_id' => $cita->id,
            'cita' => $this->fila($nueva),
        ]);
    }

    /**
     * Anular. No borrar.
     *
     * El motivo no es opcional. Una cita anulada sin motivo no sirve para nada:
     * ni para cobrar, ni para saber si el paciente avisó, ni para el caso de
     * que alguien pregunte por qué.
     */
    public function destroy(Request $peticion, Cita $cita): JsonResponse
    {
        $this->authorize('anular', $cita);

        if ($cita->estado->esHecho()) {
            return $this->error('La cita ya tiene un desenlace registrado. No se puede anular.');
        }

        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:240'],
        ]);

        $cita->forceFill([
            'estado' => EstadoCita::Anulada,
            'motivo' => $datos['motivo'],
        ])->save();

        return response()->json([
            'mensaje' => 'Cita anulada.',
            'id' => $cita->id,
            'estado' => $cita->estado->value,
        ]);
    }

    /**
     * Registrar el desenlace: atendida o no asistio.
     *
     * Ruta aparte de la anulacion porque anular es una decision de agenda y
     * esto es un hecho clinico: queda en la historia del paciente. Solo el
     * profesional lo escribe. Ver CitaPolicy::registrarDesenlace.
     */
    public function desenlace(Request $peticion, Cita $cita): JsonResponse
    {
        $this->authorize('registrarDesenlace', $cita);

        $datos = $peticion->validate([
            'estado' => ['required', 'string'],
        ]);

        $estado = EstadoCita::tryFrom($datos['estado']);

        if ($estado === null || ! in_array($estado, [EstadoCita::Atendida, EstadoCita::NoAsistio], true)) {
            return $this->error('El desenlace de una cita solo puede ser atendida o no asistio.');
        }

        $cita->forceFill([
            'estado' => $estado,
            'motivo' => $estado === EstadoCita::NoAsistio
                ? 'El paciente no asistio.'
                : $cita->motivo,
        ])->save();

        return response()->json([
            'mensaje' => 'Desenlace registrado.',
            'id' => $cita->id,
            'estado' => $cita->estado->value,
        ]);
    }

    /**
     * Una cita, en la forma que consume la interfaz.
     *
     * @return array<string, mixed>
     */
    private function fila(Cita $cita, bool $completo = false): array
    {
        $fila = [
            'id' => $cita->id,
            'fecha_inicio' => $cita->fecha_inicio?->toIso8601String(),
            'fecha_fin' => $cita->fecha_fin?->toIso8601String(),
            'duracion_minutos' => $cita->duracion_minutos,
            'estado' => $cita->estado->value,
            'estado_etiqueta' => $cita->estado->etiqueta(),
            'ocupa_agenda' => $cita->estado->ocupaAgenda(),
            'silla' => $cita->silla,
            'motivo' => $cita->motivo,
            'procedimiento' => $cita->procedimiento === null ? null : [
                'id' => $cita->procedimiento->id,
                'codigo' => $cita->procedimiento->codigo,
                'nombre' => $cita->procedimiento->nombre,
            ],
            'profesional' => $cita->profesional === null ? null : [
                'id' => $cita->profesional->id,
                'nombre' => $cita->profesional->nombre,
                'color_agenda' => $cita->profesional->color_agenda ?? null,
            ],
            'paciente' => $cita->paciente === null ? null : [
                'id' => $cita->paciente->id,
                'nombre' => trim($cita->paciente->nombres . ' ' . $cita->paciente->apellidos),
                'telefono' => $cita->paciente->telefono ?? null,
            ],
            'sobre_desbloqueo' => $cita->desbloqueo_alergia_id !== null,
        ];

        if ($completo) {
            $fila['notas'] = $cita->notas;
            $fila['desbloqueo'] = $cita->rebloqueo === null ? null : [
                'id' => $cita->rebloqueo->id,
                'motivo' => $cita->rebloqueo->motivo,
                'concedido' => $cita->rebloqueo->concedido?->toIso8601String(),
                'vigente_hasta' => $cita->rebloqueo->vigente_hasta,
            ];
        }

        return $fila;
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['mensaje' => $mensaje], 422);
    }
}