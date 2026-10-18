<?php

declare(strict_types=1);

namespace App\Http\Controllers\Odontograma;

use App\Http\Controllers\Controller;
use App\Models\Odontograma;
use App\Models\Paciente;
use App\Services\Odontograma\PiezaFdi;
use App\Services\Odontograma\ServicioOdontograma;
use Illuminate\Http\JsonResponse;

/**
 * La cronologia de las piezas: que se le hizo a cada pieza y cuando.
 *
 * ESTA ES LA RUTA MAS CERRADA DE LA APLICACION
 * --------------------------------------------
 * Una cronologia es informacion de salud con fecha y con profesional, y es lo
 * unico de este sistema que se parece a una ficha clinica tradicional. Por eso:
 *
 *   - Exige rol `profesional` o `administrador` (CapacidadesDeUsuario).
 *   - Exige ademas que el profesional trate al paciente. Ver
 *     PacientePolicy::verCronologia y el middleware `odontograma.auditado`.
 *   - CADA INTENTO QUEDA ANOTADO, permitido o denegado, en
 *     `accesos_odontograma`.
 *   - Responde 404 y no 403 cuando rechaza, para no confirmar que el paciente
 *     existe.
 *
 * Ver docs/07.
 */
class CronologiaController extends Controller
{
    public function __construct(private readonly ServicioOdontograma $servicio) {}

    /**
     * Cronologia de una pieza.
     *
     * La pieza se pide por numero FDI en la ruta (`{numero_fdi}`), no por
     * identificador de fila: el numero es lo que el profesional tiene en la
     * cabeza cuando esta sentado frente al paciente.
     */
    public function show(Paciente $paciente, string $numeroFdi): JsonResponse
    {
        $numero = (int) $numeroFdi;

        if (! PiezaFdi::esValido($numero)) {
            return response()->json([
                'mensaje' => 'El número de pieza no es válido en notación FDI. Las piezas permanentes van de 11 a 48.',
            ], 422);
        }

        $this->authorize('verCronologia', $paciente);

        $intervenciones = $this->servicio->cronologiaDe($numero, $paciente->id);

        $odontograma = $paciente->odontograma()->first();

        $estadoActual = null;

        if ($odontograma !== null) {
            $pieza = $this->servicio->piezaDe($odontograma, $numero);

            $estadoActual = [
                'estado' => $pieza->estado->value,
                'estado_etiqueta' => $pieza->estado->etiqueta(),
                'estado_desde' => $pieza->estado_desde?->toIso8601String(),
                'dias_en_estado' => $pieza->diasDesdeElUltimoCambio(),
            ];
        }

        return response()->json([
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombreCompleto(),
            ],
            'pieza' => [
                'numero_fdi' => $numero,
                'nombre' => PiezaFdi::formatear($numero),
                'arcada' => PiezaFdi::arcada($numero),
                'lado' => PiezaFdi::lado($numero),
                'tipo' => PiezaFdi::nombre($numero),
            ],
            'estado_actual' => $estadoActual,
            'total' => $intervenciones->count(),
            'intervenciones' => $intervenciones->map(
                static fn ($intervencion): array => [
                    'id' => $intervencion->id,
                    'fecha' => $intervencion->fecha?->toIso8601String(),
                    'fecha_registro' => $intervencion->fecha_registro?->toIso8601String(),
                    'descripcion' => $intervencion->descripcion,
                    'estado_anterior' => $intervencion->estado_anterior,
                    'estado_resultante' => $intervencion->estado_resultante,
                    'numero_fdi' => $intervencion->numero_fdi,
                    'profesional' => $intervencion->profesional === null ? null : [
                        'id' => $intervencion->profesional->id,
                        'nombre' => $intervencion->profesional->nombre,
                    ],
                    'procedimiento' => $intervencion->procedimiento === null ? null : [
                        'id' => $intervencion->procedimiento->id,
                        'nombre' => $intervencion->procedimiento->nombre,
                    ],
                    'plan' => $intervencion->plan === null ? null : [
                        'id' => $intervencion->plan->id,
                        'nombre' => $intervencion->plan->nombre,
                    ],
                    'notas' => $intervencion->notas,
                ],
            )->all(),
        ]);
    }

    /**
     * Cronologia completa del paciente, agrupada por pieza.
     *
     * Ahi esta el patron de historial clinico por fecha que usan las demas
     * aplicaciones de Hefesto: primero la fecha, porque la pregunta que se le
     * hace al sistema casi siempre es "que le hicieron en esa visita", y no
     * "que le hicieron en el molar de arriba a la derecha".
     *
     * @param  Odontograma  $odontograma  solo para el permiso, que compara el tenant
     */
    public function completa(Paciente $paciente, Odontograma $odontograma): JsonResponse
    {
        $this->authorize('verCronologia', $odontograma);

        $intervenciones = $this->servicio->cronologiaCompleta($paciente->id);

        $porFecha = $intervenciones
            ->groupBy(static fn ($intervencion): string => $intervencion->fecha?->toDateString() ?? 'sin fecha')
            ->map(static fn ($grupo): array => $grupo->map(
                static fn ($intervencion): array => [
                    'id' => $intervencion->id,
                    'hora' => $intervencion->fecha?->format('H:i'),
                    'numero_fdi' => $intervencion->numero_fdi,
                    'pieza' => PiezaFdi::formatear($intervencion->numero_fdi),
                    'descripcion' => $intervencion->descripcion,
                    'estado_anterior' => $intervencion->estado_anterior,
                    'estado_resultante' => $intervencion->estado_resultante,
                    'profesional' => $intervencion->profesional?->nombre,
                ],
            )->values()->all())
            ->all();

        return response()->json([
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombreCompleto(),
            ],
            'total' => $intervenciones->count(),
            'por_fecha' => $porFecha,
        ]);
    }
}