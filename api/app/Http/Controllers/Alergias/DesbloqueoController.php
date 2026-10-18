<?php

declare(strict_types=1);

namespace App\Http\Controllers\Alergias;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarDesbloqueoRequest;
use App\Models\Alergia;
use App\Models\Condicion;
use App\Models\DesbloqueoAlergia;
use App\Models\Paciente;
use App\Models\Profesional;
use App\Models\Procedimiento;
use App\Services\Alergias\ObstacloAtencion;
use App\Services\Alergias\RevisorDeObstaclos;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Desbloqueos de alergia y de condicion.
 *
 * QUE ES UN DESBLOQUEO
 * --------------------
 * Es la unica via por la que una atencion que el sistema considera prohibida
 * se puede hacer igual. En otras palabras: es el mecanismo por el que el
 * software dice "aqui hay riesgo, y alguien lo firmo".
 *
 * REGLAS QUE NO SE NEGOCIAN
 * -------------------------
 *   1. Solo un profesional con ficha de profesional lo concede. Recepcion no,
 *      y el administrador del consultorio tampoco: la direccion no es un cargo
 *      clinico. Ver DesbloqueoPolicy.
 *   2. Sin `motivo` no hay desbloqueo. El motivo queda en la historia clinica y
 *      es lo que va a explicar la decision dentro de tres anos.
 *   3. Un desbloqueo nunca se borra ni se edita. Se cierra poniendo
 *      `vigente_hasta` en el dia de hoy, y el registro original permanece.
 *   4. El desbloqueo se guarda DENTRO de la cita (`desbloqueo_alergia_id`), de
 *      modo que al mirar la agenda se ve de inmediato que esa cita se agendo
 *      sobre una alergia con autorizacion registrada, y no que no habia ninguna.
 *      Ver ServicioAgenda.
 *
 * LA VIGENCIA NO SE CALCULA CON UN NUMERO DE DIAS
 * ----------------------------------------------
 * Por omision un desbloqueo no vence: vale hasta que un profesional lo cierre.
 * Si el consultorio quiere un limite, se configura
 * `clinica-dental.alergias.vigencia_desbloqueo_dias`. No hay un valor por
 * omision en el software, porque un plazo inventado seria exactamente el tipo
 * de dato que despues nadie sabe explicar. Ver docs/07.
 */
class DesbloqueoController extends Controller
{
    /**
     * Desbloqueos de un paciente, vigentes y cerrados.
     *
     * Los tres roles los ven. Si recepcion no los viera, veria una cita agendada
     * sobre una alergia sin entender por que se permitio.
     */
    public function index(Paciente $paciente): JsonResponse
    {
        $this->authorize('viewAny', DesbloqueoAlergia::class);

        $hoy = now()->toDateString();

        $desbloqueos = DesbloqueoAlergia::query()
            ->where('tenant_id', $this->consultorioId())
            ->where('paciente_id', $paciente->id)
            ->with([
                'alergia:id,tenant_id,sustancia,reaccion,severidad',
                'condicion:id,tenant_id,condicion,bloquea_atencion',
                'profesional:id,tenant_id,nombre,especialidad',
                'procedimiento:id,tenant_id,nombre',
            ])
            ->orderByDesc('concedido')
            ->get();

        return response()->json([
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombreCompleto(),
            ],
            'vigentes' => $desbloqueos->filter(
                static fn (DesbloqueoAlergia $d): bool => $d->vigenteEn($hoy),
            )->count(),
            'datos' => $desbloqueos->map(
                static fn (DesbloqueoAlergia $desbloqueo): array => [
                    'id' => $desbloqueo->id,
                    'motivo' => $desbloqueo->motivo,
                    'concedido' => $desbloqueo->concedido?->toIso8601String(),
                    'vigente_hasta' => $desbloqueo->vigente_hasta,
                    'vigente_en_hoy' => $desbloqueo->vigenteEn($hoy),
                    'alergia' => $desbloqueo->alergia === null ? null : [
                        'id' => $desbloqueo->alergia->id,
                        'sustancia' => $desbloqueo->alergia->sustancia,
                        'reaccion' => $desbloqueo->alergia->reaccion,
                        'severidad' => $desbloqueo->alergia->severidad->value,
                    ],
                    'condicion' => $desbloqueo->condicion === null ? null : [
                        'id' => $desbloqueo->condicion->id,
                        'condicion' => $desbloqueo->condicion->condicion,
                    ],
                    'procedimiento' => $desbloqueo->procedimiento?->nombre,
                    'concedido_por' => $desbloqueo->profesional?->nombre,
                ],
            )->all(),
        ]);
    }

    /**
     * Conceder un desbloqueo.
     */
    public function store(GuardarDesbloqueoRequest $peticion, Paciente $paciente): JsonResponse
    {
        $this->authorize('create', DesbloqueoAlergia::class);

        $datos = $peticion->validated();
        $consultorioId = $this->consultorioId();

        $profesional = Profesional::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($this->usuario()->profesional_id)
            ->where('activo', true)
            ->first();

        if ($profesional === null) {
            return response()->json([
                'mensaje' => 'La cuenta no tiene una ficha de profesional activa en este consultorio.',
            ], 422);
        }

        $alergia = null;
        $condicion = null;

        if (! empty($datos['alergia_id'])) {
            $alergia = Alergia::query()
                ->where('tenant_id', $consultorioId)
                ->where('paciente_id', $paciente->id)
                ->where('activa', true)
                ->whereKey($datos['alergia_id'])
                ->first();

            if ($alergia === null) {
                return response()->json([
                    'mensaje' => 'La alergia no existe en este consultorio o ya fue cerrada.',
                ], 422);
            }
        }

        if (! empty($datos['condicion_id'])) {
            $condicion = Condicion::query()
                ->where('tenant_id', $consultorioId)
                ->where('paciente_id', $paciente->id)
                ->where('activa', true)
                ->whereKey($datos['condicion_id'])
                ->first();

            if ($condicion === null) {
                return response()->json([
                    'mensaje' => 'La condicion no existe en este consultorio o ya fue cerrada.',
                ], 422);
            }
        }

        $procedimiento = null;

        if (! empty($datos['procedimiento_id'])) {
            $procedimiento = Procedimiento::query()
                ->where('tenant_id', $consultorioId)
                ->whereKey($datos['procedimiento_id'])
                ->first();

            if ($procedimiento === null) {
                return response()->json(['mensaje' => 'El procedimiento no existe en este consultorio.'], 422);
            }
        }

        // Vigencia por omision: no vence. El plazo por omision del software es
        // `null` a proposito, y la razon esta escrita en docs/07 y en
        // config/clinica-dental.php.
        $vigenciaDias = config('clinica-dental.alergias.vigencia_desbloqueo_dias');

        $vigenteHasta = $datos['vigente_hasta'] ?? null;

        if ($vigenteHasta === null && is_int($vigenciaDias) && $vigenciaDias > 0) {
            $vigenteHasta = Carbon::now()->addDays($vigenciaDias)->toDateString();
        }

        $desbloqueo = DesbloqueoAlergia::query()->create([
            'tenant_id' => $consultorioId,
            'paciente_id' => $paciente->id,
            'alergia_id' => $alergia?->id,
            'condicion_id' => $condicion?->id,
            'profesional_id' => $profesional->id,
            'procedimiento_id' => $procedimiento?->id,
            'motivo' => $datos['motivo'],
            'concedido' => now(),
            'vigente_hasta' => $vigenteHasta,
        ]);

        return response()->json([
            'mensaje' => 'Desbloqueo registrado. Queda anotado en la historia clinica del paciente.',
            'desbloqueo' => [
                'id' => $desbloqueo->id,
                'motivo' => $desbloqueo->motivo,
                'concedido_por' => $profesional->nombre,
                'vigente_hasta' => $desbloqueo->vigente_hasta,
                'vence_por_configuracion' => $vigenciaDias,
            ],
        ], 201);
    }

    /**
     * Cerrar un desbloqueo.
     *
     * No se borra: se deja de aplicar. Poner `vigente_hasta` en hoy es lo que
     * hace que deje de cubrir citas futuras, sin perder el registro de que
     * existio ni quien lo concedio.
     */
    public function destroy(DesbloqueoAlergia $desbloqueo): JsonResponse
    {
        $this->authorize('cerrar', $desbloqueo);

        $desbloqueo->forceFill([
            'vigente_hasta' => now()->toDateString(),
        ])->save();

        return response()->json([
            'mensaje' => 'Desbloqueo cerrado. El registro se conserva.',
            'id' => $desbloqueo->id,
            'vigente_hasta' => $desbloqueo->vigente_hasta,
        ]);
    }

    /**
     * Comprobacion previa: por que no se puede agendar, y que habria que
     * desbloquear.
     *
     * La pantalla la llama ANTES de intentar agendar, para que el profesional
     * vea el motivo del bloqueo y no un error seco. Ver
     * App\Services\Alergias\RevisorDeObstaclos.
     */
    public function comprobar(Request $peticion, Paciente $paciente): JsonResponse
    {
        $this->authorize('verAlergias', $paciente);

        $datos = $peticion->validate([
            'procedimiento_id' => ['required', 'uuid'],
            'fecha' => ['nullable', 'date'],
        ]);

        $consultorioId = $this->consultorioId();

        $procedimiento = Procedimiento::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($datos['procedimiento_id'])
            ->first();

        if ($procedimiento === null) {
            return response()->json(['mensaje' => 'El procedimiento no existe en este consultorio.'], 422);
        }

        $fecha = isset($datos['fecha']) ? Carbon::parse($datos['fecha']) : Carbon::now();

        $revisor = app(RevisorDeObstaclos::class);

        $obstaclos = $revisor->obstaclosDe($paciente, $procedimiento, $fecha);

        $desbloqueos = $revisor->desbloqueosVigentes($paciente, $procedimiento, $fecha);

        return response()->json([
            'puede_agendar' => $obstaclos === [],
            'procedimiento' => $procedimiento->nombre,
            'fecha' => $fecha->toDateTimeString(),
            'motivo' => $obstaclos === []
                ? null
                : $revisor->mensajeDeBloqueo($obstaclos, $procedimiento),
            'obstaculos' => array_map(static fn (ObstacloAtencion $obstaculo): array => [
                'tipo' => $obstaculo->esAlergia ? 'alergia' : 'condicion',
                'registro_id' => $obstaculo->registroId(),
                'descripcion' => $obstaculo->descripcion(),
            ], $obstaclos),
            'desbloqueos_vigentes' => $desbloqueos->map(
                static fn (DesbloqueoAlergia $d): array => [
                    'id' => $d->id,
                    'motivo' => $d->motivo,
                    'vigente_hasta' => $d->vigente_hasta,
                ],
            )->values()->all(),
            'puede_otorgar_desbloqueo' => $this->capacidades()->puedeDesbloquearAlergias(),
        ]);
    }
}