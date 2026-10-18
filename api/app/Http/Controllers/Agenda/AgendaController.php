<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agenda;

use App\Enums\EstadoCita;
use App\Http\Controllers\Controller;
use App\Models\Alergia;
use App\Models\Cita;
use App\Models\Condicion;
use App\Models\Paciente;
use App\Models\Procedimiento;
use App\Services\Alergias\ObstacloAtencion;
use App\Services\Alergias\RevisorDeObstaclos;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La agenda del dia, con el riesgo a la vista.
 *
 * QUE HACE ESTO Y POR QUE NO ES LO MISMO QUE `CitaController::index`
 * ----------------------------------------------------------------------
 * La lista de citas dice a quien se atiende y cuando. Esta pantalla dice, para
 * cada cita, SI HAY ALGO QUE IMPIDA ATENDER.
 *
 * Es la diferencia entre un sistema que se limita a mostrar la hora y uno en el
 * que recepcion ve, antes de que el paciente se siente, que tiene una alergia
 * anafilactica al latex. Sin esto, el sistema seria PEOR que una hoja de papel:
 * la hoja al menos se mira.
 *
 * El aviso va DENTRO de la agenda y no en un aviso aparte porque un aviso
 * aparte se lee una vez y despues deja de leerse. Ver
 * App\Services\Alergias\RevisorDeObstaclos y docs/03.
 *
 * LO QUE NO HACE
 * ---------------
 * No abre nada del historial odontologico. Ver el estado de una pieza es otra
 * ruta, con su propio permiso y su propia auditoria. Aca no hay cronologia,
 * ni notas de pieza, ni plan de tratamiento.
 */
class AgendaController extends Controller
{
    public function __construct(private readonly RevisorDeObstaclos $revisor) {}

    /**
     * Agenda de un dia, con los obstaculos de cada paciente ya resueltos.
     */
    public function show(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Cita::class);

        $datos = $peticion->validate([
            'fecha' => ['nullable', 'date'],
            'profesional_id' => ['nullable', 'uuid'],
        ]);

        $consultorioId = $this->consultorioId();

        $dia = isset($datos['fecha'])
            ? Carbon::parse($datos['fecha'])->startOfDay()
            : Carbon::now()->startOfDay();

        $citas = Cita::query()
            ->with([
                'paciente:id,tenant_id,nombres,apellidos,telefono',
                'profesional:id,tenant_id,nombre,color_agenda',
                'procedimiento:id,tenant_id,codigo,nombre,es_quirurgico,duracion_minutos',
            ])
            ->where('tenant_id', $consultorioId)
            ->whereBetween('fecha_inicio', [$dia, $dia->copy()->endOfDay()])
            ->when(isset($datos['profesional_id']), fn ($c) => $c->where('profesional_id', $datos['profesional_id']))
            ->orderBy('fecha_inicio')
            ->get();

        $filas = $citas->map(function (Cita $cita) use ($consultorioId): array {
            $obstaculos = [];

            if ($cita->paciente !== null && $cita->procedimiento !== null) {
                $obstaclos = $this->revisor->obstaclosDe(
                    $cita->paciente,
                    $cita->procedimiento,
                    $cita->fecha_inicio,
                );
            }

            return [
                'id' => $cita->id,
                'hora' => $cita->fecha_inicio?->format('H:i'),
                'hora_fin' => $cita->fecha_fin?->format('H:i'),
                'duracion_minutos' => $cita->duracion_minutos,
                'estado' => $cita->estado->value,
                'estado_etiqueta' => $cita->estado->etiqueta(),
                'silla' => $cita->silla,
                'motivo' => $cita->motivo,
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
                'procedimiento' => $cita->procedimiento === null ? null : [
                    'id' => $cita->procedimiento->id,
                    'nombre' => $cita->procedimiento->nombre,
                ],
                'puede_atenderse' => $obstaclos === [],
                'obstaculos' => array_map(
                    static fn (ObstacloAtencion $obstaculo): array => [
                        'tipo' => $obstaculo->esAlergia ? 'alergia' : 'condicion',
                        'registro_id' => $obstaculo->registroId(),
                        'descripcion' => $obstaculo->descripcion(),
                    ],
                    $obstaclos,
                ),
                'sobre_desbloqueo' => $cita->desbloqueo_alergia_id !== null,
            ];
        })->all();

        $conObstaculo = count(array_filter(
            $filas,
            static fn (array $fila): bool => $fila['puede_atenderse'] === false,
        ));

        return response()->json([
            'fecha' => $dia->toDateString(),
            'total' => count($filas),
            'con_obstaculo' => $conObstaculo,
            'citas' => $filas,
            // Este contador es el que un responsable tiene que poder mirar sin
            // abrir cada ficha: dice si hoy hay algo que revisar.
            'requiere_revision' => $conObstaculo > 0,
            'consultorio_id' => $consultorioId,
        ]);
    }

    /**
     * Alergias y condiciones activas de un paciente, para el panel del
     * mostrador.
     *
     * Los tres roles. Es el mismo permiso que el de agendar: quien no puede
     * ver el riesgo al agendar no puede verlo aqui tampoco.
     */
    public function riesgos(string $pacienteId): JsonResponse
    {
        $consultorioId = $this->consultorioId();

        $paciente = Paciente::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($pacienteId)
            ->first();

        if ($paciente === null) {
            return response()->json(['mensaje' => 'No encontrado.'], 404);
        }

        $this->authorize('verAlergias', $paciente);

        $alergias = Alergia::query()
            ->where('tenant_id', $consultorioId)
            ->where('paciente_id', $paciente->id)
            ->where('activa', true)
            ->orderByDesc('fecha_registro')
            ->get();

        $condiciones = Condicion::query()
            ->where('tenant_id', $consultorioId)
            ->where('paciente_id', $paciente->id)
            ->where('activa', true)
            ->orderByDesc('fecha_registro')
            ->get();

        $procedimientos = Procedimiento::query()
            ->where('tenant_id', $consultorioId)
            ->where('activo', true)
            ->orderBy('categoria')
            ->orderBy('nombre')
            ->get(['id', 'tenant_id', 'nombre', 'categoria', 'es_quirurgico']);

        return response()->json([
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombreCompleto(),
            ],
            'alergias' => $alergias->map(static fn (Alergia $alergia): array => [
                'id' => $alergia->id,
                'sustancia' => $alergia->sustancia,
                'reaccion' => $alergia->reaccion,
                'severidad' => $alergia->severidad->value,
                'severidad_etiqueta' => $alergia->severidad->etiqueta(),
                'puede_ser_fatal' => $alergia->severidad->puedeSerFatal(),
                'fecha_registro' => $alergia->fecha_registro?->toDateString(),
            ])->all(),
            'condiciones' => $condiciones->map(static fn (Condicion $condicion): array => [
                'id' => $condicion->id,
                'condicion' => $condicion->condicion,
                'bloquea_atencion' => $condicion->bloquea_atencion,
                'requiere_autorizacion_especial' => $condicion->requiere_autorizacion_especial,
                'fecha_registro' => $condicion->fecha_registro?->toDateString(),
            ])->all(),
            'procedimientos_activos' => $procedimientos->map(static fn (Procedimiento $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'categoria' => $p->categoria,
                'es_quirurgico' => $p->es_quirurgico,
            ])->all(),
            'procedimientos_bloqueados' => $procedimientos
                ->filter(function (Procedimiento $p) use ($paciente, $consultorioId): bool {
                    return ! $this->revisor->puedeAgendar($paciente, $p, Carbon::now());
                })
                ->map(static fn (Procedimiento $p): array => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'mensaje' => $this->revisor->mensajeDeBloqueo(
                        $this->revisor->obstaclosDe($paciente, $p, Carbon::now()),
                        $p,
                    ),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Los estados que ocupan agenda y los que no.
     *
     * Se devuelve con la agenda para que la interfaz no tenga que duplicar la
     * regla. Una cita anulada o en la que el paciente no asistio sigue
     * apareciendo en el historial del dia, pero deja de bloquear el horario.
     */
    public function estados(): JsonResponse
    {
        $this->authorize('viewAny', Cita::class);

        return response()->json([
            'estados' => array_map(static fn (EstadoCita $estado): array => [
                'valor' => $estado->value,
                'etiqueta' => $estado->etiqueta(),
                'ocupa_agenda' => $estado->ocupaAgenda(),
                'es_hecho' => $estado->esHecho(),
            ], EstadoCita::cases()),
        ]);
    }
}