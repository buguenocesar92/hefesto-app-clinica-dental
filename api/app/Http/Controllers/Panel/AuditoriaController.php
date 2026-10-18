<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Enums\AccionOdontograma;
use App\Enums\ResultadoAcceso;
use App\Http\Controllers\Controller;
use App\Models\AccesoOdontograma;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lectura de la bitacora de accesos al historial odontologico.
 *
 * SOLO EL ADMINISTRADOR
 * ---------------------
 * Y no es una concesion: la bitacora dice quien abrio el historial de quien, y
 * eso es informacion sobre las personas de la institucion tanto como sobre los
 * pacientes. Si la pudiera ver quien solo ve pacientes, la bitacora se
 * convertiria en un instrumento para vigilar al personal, que es exactamente
 * para lo que no debe servir.
 *
 * Un profesional que quiso saber quien estuvo mirando la ficha de un paciente
 * concreto lo pregunta a la direccion, y quien responde lo hace desde el
 * registro, con fecha. Ver docs/07.
 *
 * LO QUE DEVUELVE Y LO QUE NO
 * ---------------------------
 * Devuelve CUENTAS: por resultado, por accion y por usuario. No devuelve el
 * detalle fila por fila. El detalle existe en `accesos_odontograma` y existe
 * para una revision formal, que no es una pantalla de uso diario: se consulta
 * con un peritaje, que tiene fecha, responsable y registro de lo que se hizo con
 * los datos.
 *
 * Por que no se expone el detalle aqui: porque una pantalla con el detalle es
 * una pantalla que se puede dejar abierta, y esa pantalla revela informacion de
 * salud de pacientes. La cuenta es util para lo que se revisa todos los meses,
 * que es la pregunta que la ley hace posible: "hubo intentos denegados".
 */
class AuditoriaController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', AccesoOdontograma::class);

        $consultorioId = $this->consultorioId();

        $datos = $peticion->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $desde = isset($datos['desde'])
            ? Carbon::parse($datos['desde'])
            : Carbon::now()->subDays(30);

        $hasta = isset($datos['hasta'])
            ? Carbon::parse($datos['hasta'])
            : Carbon::now();

        $base = AccesoOdontograma::query()
            ->where('tenant_id', $consultorioId)
            ->whereBetween('momento', [$desde, $hasta]);

        $porResultado = (clone $base)
            ->selectRaw('resultado, count(*) as total')
            ->groupBy('resultado')
            ->pluck('total', 'resultado');

        $porAccion = (clone $base)
            ->selectRaw('accion, count(*) as total')
            ->groupBy('accion')
            ->pluck('total', 'accion');

        $porUsuario = (clone $base)
            ->with('usuario:id,tenant_id,nombre,rol')
            ->selectRaw('usuario_id, count(*) as total')
            ->groupBy('usuario_id')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $denegados = (int) ($porResultado->get(ResultadoAcceso::Denegado->value) ?? 0);

        $umbralUsuario = (int) config('clinica-dental.alertas.denegados_por_usuario', 5);

        return response()->json([
            'rango' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            'total' => (clone $base)->count(),
            'por_resultado' => $porResultado->all(),
            'por_accion' => $porAccion->all(),
            'por_usuario' => $porUsuario->map(static fn (AccesoOdontograma $fila): array => [
                'usuario_id' => $fila->usuario_id,
                'nombre' => $fila->usuario?->nombre ?? 'Cuenta eliminada',
                'rol' => $fila->usuario?->rol->value,
                'total' => (int) $fila->getAttribute('total'),
            ])->all(),
            'denegados' => $denegados,
            'usuarios_sobre_el_umbral' => $porUsuario
                ->filter(static fn (AccesoOdontograma $fila): bool
                    => (int) $fila->getAttribute('total') >= $umbralUsuario)
                ->count(),
            'umbral_denegados_por_usuario' => $umbralUsuario,
            'acciones_registradas' => array_map(static fn (AccionOdontograma $accion): array => [
                'valor' => $accion->value,
                'etiqueta' => $accion->etiqueta(),
            ], AccionOdontograma::cases()),
            'resultados_registrados' => array_map(static fn (ResultadoAcceso $resultado): array => [
                'valor' => $resultado->value,
                'etiqueta' => $resultado->etiqueta(),
            ], ResultadoAcceso::cases()),
            // El aviso automatico NO esta implementado. Este campo existe para
            // que la interfaz no prometa algo que el sistema no hace.
            'aviso_automatico_implementado' => false,
            'nota' => 'La revision de la bitacora es manual. Los umbrales estan configurados, '
                . 'pero el sistema no envia ningun aviso. Ver docs/07 y docs/10.',
        ]);
    }
}