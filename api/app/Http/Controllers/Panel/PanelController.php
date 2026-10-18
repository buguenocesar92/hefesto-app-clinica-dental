<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Enums\EstadoCita;
use App\Enums\EstadoEtapa;
use App\Enums\EstadoPlan;
use App\Enums\EstadoPieza;
use App\Http\Controllers\Controller;
use App\Models\Alergia;
use App\Models\Cita;
use App\Models\Condicion;
use App\Models\Consultorio;
use App\Models\EtapaPlan;
use App\Models\Insumo;
use App\Models\IntervencionPieza;
use App\Models\Odontograma;
use App\Models\Paciente;
use App\Models\Pieza;
use App\Models\PlanTratamiento;
use App\Services\Pacientes\TiempoSinControl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * El panel de inicio: que hay que mirar hoy.
 *
 * UN PANEL QUE DIGA "HAY 12 PACIENTES" NO SIRVE
 * --------------------------------------------
 * El conteo de registros no dice nada. Lo que un consultorio necesita saber al
 * abrir el sistema en la manana es:
 *
 *   - Que citas hay hoy y cuantas se agendaron sobre una alergia con
 *     desbloqueo.
 *   - Que pacientes con alergia grave tienen cita esta semana.
 *   - Que insumos estan por debajo del minimo.
 *   - Que etapas de plan quedaron con fecha vencida.
 *
 * Por eso casi todo lo que devuelve aca son CONDICIONES (hay algo que
 * revisar), no cantidades. Ver el `requiere_revision` de cada bloque.
 *
 * LO QUE EL PANEL NO ABRE
 * -----------------------
 * No lee cronologia, ni notas de pieza, ni el texto de un plan. Contar es una
 * operacion de agregacion que no revela el contenido de la historia clinica, y
 * por eso puede vivir en una pantalla que ven los tres roles. Lo que si
 * revela historia clinica vive en rutas auditadas. Ver docs/03.
 */
class PanelController extends Controller
{
    /**
     * Resumen del dia, con lo que hay que revisar.
     */
    public function index(): JsonResponse
    {
        $consultorioId = $this->consultorioId();
        $hoy = now()->startOfDay();
        $finDeHoy = $hoy->copy()->endOfDay();

        $capacidades = $this->capacidades();

        return response()->json([
            'consultorio' => [
                'id' => $consultorioId,
                'nombre' => Consultorio::query()->find($consultorioId)?->nombre ?? 'Su consultorio',
            ],
            'fecha' => $hoy->toDateString(),
            'rol' => $this->usuario()->rol->value,
            'capacidades' => [
                'ver_estado_actual' => $capacidades->puedeVerEstadoActual(),
                'ver_cronologia' => $capacidades->puedeVerCronologia(),
                'registrar_intervencion' => $capacidades->puedeRegistrarIntervencion(),
                'ver_planes' => $capacidades->puedeVerPlanes(),
                'editar_planes' => $capacidades->puedeEditarPlanes(),
                'desbloquear_alergias' => $capacidades->puedeDesbloquearAlergias(),
                'administra' => $capacidades->administraConsultorio(),
            ],
            'citas_hoy' => $this->bloqueCitas($consultorioId, $hoy, $finDeHoy),
            'alertas' => [
                'alergia_grave_esta_semana' => $this->alergiaGrave($consultorioId, $finDeHoy),
                'condiciones_bloqueantes' => $this->condicionesBloqueantes($consultorioId),
                'insumos_bajo_stock' => $capacidades->administraConsultorio()
                    ? $this->insumosBajoStock($consultorioId)
                    : null,
                'planes_en_curso' => $capacidades->puedeVerPlanes() ? $this->planesEnCurso($consultorioId) : null,
            ],
            'pacientes_sin_control' => $this->sinControl($consultorioId),
            'odontograma' => $capacidades->puedeVerEstadoActual() ? $this->odontograma($consultorioId) : null,
        ]);
    }

    /**
     * Agenda del dia, resumida: cuantas citas y cuantas con riesgo.
     *
     * No se listan los pacientes con nombre completo: el panel es una pantalla
     * que puede quedar abierta a la vista en el mostrador. Los nombres estan en
     * la agenda, que es donde corresponde verlos.
     *
     * @return array<string, mixed>
     */
    private function bloqueCitas(string $consultorioId, Carbon $desde, Carbon $hasta): array
    {
        $porEstado = Cita::query()
            ->where('tenant_id', $consultorioId)
            ->whereBetween('fecha_inicio', [$desde, $hasta])
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'total' => (int) $porEstado->sum(),
            'por_estado' => $porEstado->all(),
            'ocupa_agenda' => Cita::query()
                ->where('tenant_id', $consultorioId)
                ->whereBetween('fecha_inicio', [$desde, $hasta])
                ->whereIn('estado', [
                    EstadoCita::Agendada->value,
                    EstadoCita::Confirmada->value,
                ])
                ->count(),
            // Citas que se agendaron sobre una alergia con desbloqueo
            // registrado. No es una alerta: es informacion para que quien mira
            // la agenda sepa que ese caso fue exceptionalmente autorizado.
            'sobre_desbloqueo' => Cita::query()
                ->where('tenant_id', $consultorioId)
                ->whereBetween('fecha_inicio', [$desde, $hasta])
                ->whereNotNull('desbloqueo_alergia_id')
                ->count(),
        ];
    }

    /**
     * Pacientes con alergia grave que tienen cita en los proximos siete dias.
     *
     * La cuenta se hace con una subconsulta de existencia: el sistema dice "hay
     * 3" sin decir de quien. Quien necesite saber de quien va a la agenda, que
     * ya cruza alergias con citas.
     *
     * @return array<string, mixed>
     */
    private function alergiaGrave(string $consultorioId, Carbon $finDeHoy): array
    {
        $graves = ['severa', 'anafilactica'];
        $semana = $finDeHoy->copy()->addDays(6);

        $cantidad = Alergia::query()
            ->where('tenant_id', $consultorioId)
            ->where('activa', true)
            ->whereIn('severidad', $graves)
            ->whereExists(function ($consulta) use ($consultorioId, $finDeHoy, $semana): void {
                $consulta->selectRaw('1')
                    ->from('citas')
                    ->whereColumn('citas.paciente_id', 'alergias.paciente_id')
                    ->where('citas.tenant_id', $consultorioId)
                    ->whereBetween('citas.fecha_inicio', [$finDeHoy, $semana])
                    ->where('citas.estado', '!=', EstadoCita::Anulada->value);
            })
            ->count();

        return [
            'total' => $cantidad,
            'requiere_revision' => $cantidad > 0,
            'mensaje' => $cantidad > 0
                ? 'Hay pacientes con alergia grave con cita esta semana. Revise la agenda antes de que se sienten.'
                : 'Sin alergias graves con cita esta semana.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function condicionesBloqueantes(string $consultorioId): array
    {
        $cantidad = Condicion::query()
            ->where('tenant_id', $consultorioId)
            ->where('activa', true)
            ->where('bloquea_atencion', true)
            ->count();

        return [
            'total' => $cantidad,
            'requiere_revision' => $cantidad > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function insumosBajoStock(string $consultorioId): array
    {
        $insumos = Insumo::query()
            ->where('tenant_id', $consultorioId)
            ->where('activo', true)
            ->whereColumn('stock_actual', '<', 'stock_minimo')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'unidad', 'stock_actual', 'stock_minimo']);

        return [
            'total' => $insumos->count(),
            'requiere_revision' => $insumos->isNotEmpty(),
            'datos' => $insumos->map(static fn (Insumo $insumo): array => [
                'id' => $insumo->id,
                'nombre' => $insumo->nombre,
                'stock_actual' => $insumo->stock_actual,
                'stock_minimo' => $insumo->stock_minimo,
                'unidad' => $insumo->unidad,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planesEnCurso(string $consultorioId): array
    {
        $planes = PlanTratamiento::query()
            ->where('tenant_id', $consultorioId)
            ->whereIn('estado', [
                EstadoPlan::Presentado->value,
                EstadoPlan::Aprobado->value,
                EstadoPlan::EnCurso->value,
            ])
            ->count();

        $vencidas = EtapaPlan::query()
            ->where('tenant_id', $consultorioId)
            ->where('estado', EstadoEtapa::Programada->value)
            ->whereNotNull('fecha_prevista')
            ->whereDate('fecha_prevista', '<', now()->toDateString())
            ->count();

        return [
            'total' => $planes,
            'etapas_con_fecha_vencida' => $vencidas,
            'requiere_revision' => $vencidas > 0,
        ];
    }

    /**
     * Pacientes activos cuya ultima atencion fue hace mucho.
     *
     * Es el motivo por el que un consultorio llama a un paciente: la profilaxis
     * anual. Aca hay un numero y una antiguedad maxima, no nombres: la lista con
     * nombres es la de pacientes, que es una ruta normal.
     *
     * @return array<string, mixed>
     */
    private function sinControl(string $consultorioId, int $umbralDias = 365): array
    {
        $pacientes = Paciente::query()
            ->where('tenant_id', $consultorioId)
            ->where('activo', true)
            ->get(['id', 'nombres', 'apellidos', 'fecha_nacimiento']);

        $mayorDias = 0;

        foreach ($pacientes as $paciente) {
            $dias = (new TiempoSinControl($paciente))->dias();

            if ($dias !== null) {
                $mayorDias = max($mayorDias, $dias);
            }
        }

        return [
            'pacientes_activos' => $pacientes->count(),
            'antiguedad_maxima_dias' => $mayorDias > 0 ? $mayorDias : null,
            'umbral_dias' => $umbralDias,
            'requiere_revision' => $mayorDias >= $umbralDias,
        ];
    }

    /**
     * Resumen del odontograma, por estado de pieza.
     *
     * Es un `GROUP BY` sobre `piezas`: dice cuantos dientes hay obturados, no
     * de quien ni de cuando. La cronologia es otra ruta, con otro permiso y su
     * auditoria. Ver docs/03.
     *
     * @return array<string, mixed>
     */
    private function odontograma(string $consultorioId): array
    {
        $porEstado = Pieza::query()
            ->where('tenant_id', $consultorioId)
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'odontogramas' => Odontograma::query()->where('tenant_id', $consultorioId)->count(),
            'piezas_totales' => (int) $porEstado->sum(),
            'por_estado' => $porEstado->all(),
            'intervenciones_30_dias' => IntervencionPieza::query()
                ->where('tenant_id', $consultorioId)
                ->where('fecha', '>=', now()->subDays(30))
                ->count(),
            'estados_posibles' => array_map(
                static fn (EstadoPieza $estado): array => [
                    'valor' => $estado->value,
                    'etiqueta' => $estado->etiqueta(),
                    'abreviatura' => $estado->abreviatura(),
                    'grupo' => $estado->grupo(),
                ],
                EstadoPieza::cases(),
            ),
        ];
    }
}