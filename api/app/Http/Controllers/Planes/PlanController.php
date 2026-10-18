<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planes;

use App\Enums\EstadoEtapa;
use App\Enums\EstadoPlan;
use App\Http\Controllers\Controller;
use App\Models\EtapaPlan;
use App\Models\Paciente;
use App\Models\PlanTratamiento;
use App\Models\Profesional;
use App\Models\Procedimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planes de tratamiento y sus etapas.
 *
 * QUE ES UN PLAN
 * --------------
 * Un plan es un documento clinico con lo que se propone hacer: que piezas, en
 * que orden, con que procedimiento y por cuanto. Lo propone el profesional y lo
 * aprueba el paciente; por eso tiene estados (`borrador`, `presentado`,
 * `aprobado`, `en_curso`, `completado`, `suspendido`, `rechazado`) y no un
 * simple booleano.
 *
 * POR QUE NO LO VE RECEPCION
 * -------------------------
 * El diagnostico y el presupuesto clinico son informacion de salud. Recepcion
 * necesita saber si el paciente tiene cita, no que le van a sacar seis piezas.
 * Ver PlanTratamientoPolicy y docs/03.
 *
 * ESTA RUTA ESTA AUDITADA
 * -----------------------
 * Cuelga del middleware `odontograma.auditado`, con la accion `ver_plan` y
 * `editar_plan`. Un plan de tratamiento es parte de la historia clinica y se
 * anota igual que el resto.
 */
class PlanController extends Controller
{
    /**
     * Planes de un paciente.
     */
    public function index(Paciente $paciente): JsonResponse
    {
        $this->authorize('viewAny', PlanTratamiento::class);

        $planes = PlanTratamiento::query()
            ->where('tenant_id', $this->consultorioId())
            ->where('paciente_id', $paciente->id)
            ->with([
                'profesional:id,tenant_id,nombre',
                'etapas' => fn ($consulta) => $consulta->orderBy('orden'),
            ])
            ->orderByDesc('fecha_inicio')
            ->get();

        return response()->json([
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombreCompleto(),
            ],
            'total' => $planes->count(),
            'planes' => $planes->map(
                static fn (PlanTratamiento $plan): array => [
                    'id' => $plan->id,
                    'nombre' => $plan->nombre,
                    'estado' => $plan->estado->value,
                    'estado_etiqueta' => $plan->estado->etiqueta(),
                    'profesional' => $plan->profesional?->nombre,
                    'presupuesto_clp' => $plan->presupuesto_clp,
                    'fecha_inicio' => $plan->fecha_inicio,
                    'fecha_fin' => $plan->fecha_fin,
                    'avance' => $plan->avance(),
                    'etapas' => $plan->etapas->map(
                        static fn (EtapaPlan $etapa): array => [
                            'id' => $etapa->id,
                            'orden' => $etapa->orden,
                            'nombre' => $etapa->nombre,
                            'numero_fdi' => $etapa->numero_fdi,
                            'estado' => $etapa->estado->value,
                            'estado_etiqueta' => $etapa->estado->etiqueta(),
                            'fecha_prevista' => $etapa->fecha_prevista,
                        ],
                    )->all(),
                ],
            )->all(),
        ]);
    }

    public function show(PlanTratamiento $plan): JsonResponse
    {
        $this->authorize('view', $plan);

        $plan->load([
            'paciente:id,tenant_id,nombres,apellidos',
            'profesional:id,tenant_id,nombre,especialidad',
            'etapas' => fn ($consulta) => $consulta->with('procedimiento:id,tenant_id,nombre,codigo')->orderBy('orden'),
        ]);

        return response()->json([
            'id' => $plan->id,
            'nombre' => $plan->nombre,
            'diagnostico' => $plan->diagnostico,
            'estado' => $plan->estado->value,
            'estado_etiqueta' => $plan->estado->etiqueta(),
            'presupuesto_clp' => $plan->presupuesto_clp,
            'fecha_inicio' => $plan->fecha_inicio,
            'fecha_fin' => $plan->fecha_fin,
            'fecha_presentacion' => $plan->fecha_presentacion,
            'fecha_aprobacion' => $plan->fecha_aprobacion,
            'observaciones' => $plan->observaciones,
            'avance' => $plan->avance(),
            'paciente' => $plan->paciente === null ? null : [
                'id' => $plan->paciente->id,
                'nombre' => $plan->paciente->nombreCompleto(),
            ],
            'profesional' => $plan->profesional?->nombre,
            'etapas' => $plan->etapas->map(
                static fn (EtapaPlan $etapa): array => [
                    'id' => $etapa->id,
                    'orden' => $etapa->orden,
                    'nombre' => $etapa->nombre,
                    'descripcion' => $etapa->descripcion,
                    'numero_fdi' => $etapa->numero_fdi,
                    'estado' => $etapa->estado->value,
                    'estado_etiqueta' => $etapa->estado->etiqueta(),
                    'fecha_prevista' => $etapa->fecha_prevista,
                    'fecha_realizada' => $etapa->fecha_realizada?->toIso8601String(),
                    'presupuesto_clp' => $etapa->presupuesto_clp,
                    'procedimiento' => $etapa->procedimiento?->nombre,
                ],
            )->all(),
        ]);
    }

    /**
     * Crear un plan, en borrador.
     *
     * El plan nace SIEMPRE en borrador. Pasarlo a `presentado` es una accion
     * aparte (`cambiarEstado`), porque presentarle algo al paciente es un hecho
     * distinto de anotarlo.
     */
    public function store(Request $peticion, Paciente $paciente): JsonResponse
    {
        $this->authorize('create', PlanTratamiento::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:160'],
            'diagnostico' => ['nullable', 'string', 'max:2000'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'presupuesto_clp' => ['nullable', 'integer', 'min:0'],
        ]);

        $consultorioId = $this->consultorioId();

        $profesional = Profesional::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($this->usuario()->profesional_id)
            ->first();

        if ($profesional === null) {
            return response()->json([
                'mensaje' => 'La cuenta no tiene una ficha de profesional en este consultorio.',
            ], 422);
        }

        $plan = PlanTratamiento::query()->create(array_merge($datos, [
            'tenant_id' => $consultorioId,
            'paciente_id' => $paciente->id,
            'profesional_id' => $profesional->id,
            'estado' => EstadoPlan::Borrador->value,
        ]));

        return response()->json([
            'mensaje' => 'Plan creado en borrador.',
            'id' => $plan->id,
        ], 201);
    }

    public function update(Request $peticion, PlanTratamiento $plan): JsonResponse
    {
        $this->authorize('update', $plan);

        // Mover el estado tiene su propia ruta y su propio permiso: cambiar el
        // texto de una etapa y aprobar el plan no son lo mismo.
        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'string', 'min:3', 'max:160'],
            'diagnostico' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'fecha_fin' => ['sometimes', 'nullable', 'date', 'after_or_equal:fecha_inicio'],
            'presupuesto_clp' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'observaciones' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $plan->fill($datos);
        $plan->save();

        return response()->json(['mensaje' => 'Plan actualizado.', 'id' => $plan->id]);
    }

    /**
     * Mover el plan de estado.
     *
     * Las transiciones se validan con `EstadoPlan`: no es libre pasar de
     * `borrador` a `completado`. La fecha de presentacion y la de aprobacion las
     * pone el sistema segun el estado al que se llega, porque se deducen del
     * estado y no son datos que se escriban a mano.
     */
    public function cambiarEstado(Request $peticion, PlanTratamiento $plan): JsonResponse
    {
        $this->authorize('cambiarEstado', $plan);

        $datos = $peticion->validate([
            'estado' => ['required', 'string'],
        ]);

        $nuevo = EstadoPlan::tryFrom($datos['estado']);

        if ($nuevo === null) {
            return response()->json(['mensaje' => 'Estado de plan desconocido.'], 422);
        }

        if (! $this->transicionValida($plan->estado, $nuevo)) {
            return response()->json([
                'mensaje' => sprintf(
                    'No se puede pasar un plan de "%s" a "%s".',
                    $plan->estado->etiqueta(),
                    $nuevo->etiqueta(),
                ),
            ], 422);
        }

        $cambios = ['estado' => $nuevo->value];

        if ($nuevo === EstadoPlan::Presentado && $plan->fecha_presentacion === null) {
            $cambios['fecha_presentacion'] = now()->toDateString();
        }

        if ($nuevo === EstadoPlan::Aprobado && $plan->fecha_aprobacion === null) {
            $cambios['fecha_aprobacion'] = now()->toDateString();
        }

        $plan->forceFill($cambios)->save();

        return response()->json([
            'mensaje' => 'Estado del plan actualizado.',
            'id' => $plan->id,
            'estado' => $plan->estado->value,
            'estado_etiqueta' => $plan->estado->etiqueta(),
        ]);
    }

    /**
     * Agregar una etapa al plan.
     *
     * El orden se recalcula al agregar: mandarlo desde el cliente haria que dos
     * etapas quedaran con el mismo orden y el plan dejaria de tener un orden
     * legible.
     */
    public function agregarEtapa(Request $peticion, PlanTratamiento $plan): JsonResponse
    {
        $this->authorize('update', $plan);

        if (! $plan->estado->admiteEtapas()) {
            return response()->json([
                'mensaje' => sprintf(
                    'Un plan en estado "%s" no admite etapas nuevas.',
                    $plan->estado->etiqueta(),
                ),
            ], 422);
        }

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'numero_fdi' => ['nullable', 'integer', 'min:11', 'max:48'],
            'pieza_id' => ['nullable', 'uuid'],
            'procedimiento_id' => ['nullable', 'uuid'],
            'fecha_prevista' => ['nullable', 'date'],
            'presupuesto_clp' => ['nullable', 'integer', 'min:0'],
        ]);

        $consultorioId = $this->consultorioId();

        $procedimientoId = null;

        if (! empty($datos['procedimiento_id'])) {
            $existe = Procedimiento::query()
                ->where('tenant_id', $consultorioId)
                ->whereKey($datos['procedimiento_id'])
                ->exists();

            if (! $existe) {
                return response()->json(['mensaje' => 'El procedimiento no existe en este consultorio.'], 422);
            }

            $procedimientoId = $datos['procedimiento_id'];
        }

        $siguiente = (int) $plan->etapas()->max('orden') + 1;

        $etapa = EtapaPlan::query()->create([
            'tenant_id' => $consultorioId,
            'plan_tratamiento_id' => $plan->id,
            'pieza_id' => $datos['pieza_id'] ?? null,
            'numero_fdi' => $datos['numero_fdi'] ?? null,
            'orden' => $siguiente,
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'estado' => EstadoEtapa::Pendiente->value,
            'profesional_id' => $plan->profesional_id,
            'procedimiento_id' => $procedimientoId,
            'fecha_prevista' => $datos['fecha_prevista'] ?? null,
            'presupuesto_clp' => $datos['presupuesto_clp'] ?? null,
        ]);

        return response()->json([
            'mensaje' => 'Etapa agregada.',
            'etapa' => ['id' => $etapa->id, 'orden' => $etapa->orden],
        ], 201);
    }

    /**
     * Mover una etapa de estado: se realizo, se programo, se omite.
     */
    public function estadoEtapa(Request $peticion, EtapaPlan $etapa): JsonResponse
    {
        $this->authorize('update', PlanTratamiento::query()->findOrFail($etapa->plan_tratamiento_id));

        $datos = $peticion->validate([
            'estado' => ['required', 'string'],
        ]);

        $nuevo = EstadoEtapa::tryFrom($datos['estado']);

        if ($nuevo === null) {
            return response()->json(['mensaje' => 'Estado de etapa desconocido.'], 422);
        }

        $cambios = ['estado' => $nuevo->value];

        if ($nuevo === EstadoEtapa::Realizada && $etapa->fecha_realizada === null) {
            $cambios['fecha_realizada'] = now();
        }

        $etapa->forceFill($cambios)->save();

        return response()->json([
            'mensaje' => 'Etapa actualizada.',
            'id' => $etapa->id,
            'estado' => $etapa->estado->value,
        ]);
    }

    /**
     * Transiciones de estado del plan.
     *
     * Se declaran aca, en un solo lugar, y no repartidas entre el controlador y
     * la interfaz. La interfaz recibe la lista y no ofrece lo que no se puede.
     * El servidor la vuelve a comprobar igual: la lista es una ayuda, no el
     * control.
     */
    private function transicionValida(EstadoPlan $anterior, EstadoPlan $nuevo): bool
    {
        $permitidas = [
            EstadoPlan::Borrador->value => [EstadoPlan::Presentado->value, EstadoPlan::Rechazado->value],
            EstadoPlan::Presentado->value => [EstadoPlan::Aprobado->value, EstadoPlan::Rechazado->value],
            EstadoPlan::Aprobado->value => [EstadoPlan::EnCurso->value, EstadoPlan::Suspendido->value],
            EstadoPlan::EnCurso->value => [EstadoPlan::Completado->value, EstadoPlan::Suspendido->value],
            EstadoPlan::Suspendido->value => [EstadoPlan::EnCurso->value, EstadoPlan::Rechazado->value],
            EstadoPlan::Completado->value => [],
            EstadoPlan::Rechazado->value => [EstadoPlan::Borrador->value],
        ];

        if ($anterior === $nuevo) {
            return true;
        }

        return in_array($nuevo->value, $permitidas[$anterior->value] ?? [], true);
    }
}