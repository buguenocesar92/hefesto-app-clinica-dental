<?php

declare(strict_types=1);

namespace App\Http\Controllers\Odontograma;

use App\Enums\EstadoPieza;
use App\Http\Controllers\Controller;
use App\Models\Odontograma;
use App\Models\Paciente;
use App\Services\Odontograma\PiezaFdi;
use App\Services\Odontograma\ServicioOdontograma;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El mapa dental: estado actual de las 32 piezas permanentes.
 *
 * DOS NIVELES DE PERMISO EN LA MISMA PANTALLA
 * -------------------------------------------
 * El mapa (el estado de hoy) lo ven los tres roles. Las notas de cada pieza, no.
 * Por eso el permiso se resuelve DENTRO del controlador segun la capacidad, y
 * no con una sola comprobacion: si el permiso fuera uno solo, habria que
 * esconder la columna de notas para recepcion, y esconderla en la interfaz es
 * una forma de no protegerla.
 *
 * LA AUDITORIA NO ESTA EN ESTE ARCHIVO
 * -----------------------------------
 * Esta ruta cuelga del middleware `odontograma.auditado`, que anota cada
 * intento en `accesos_odontograma` antes de que el controlador se ejecute. Ver
 * bootstrap/app.php y App\Http\Middleware\AuditarOdontograma.
 *
 * EL MAPA NO SE EDITA
 * -------------------
 * No hay `update` ni `delete`. El estado de una pieza se cambia registrando una
 * intervencion, que escribe un hecho fechado y firmado por un profesional. Ver
 * IntervencionController y OdontogramaPolicy.
 */
class OdontogramaController extends Controller
{
    public function __construct(private readonly ServicioOdontograma $servicio) {}

    /**
     * Mapa de un paciente. Lo crea si no existe.
     *
     * Que se cree al primer vistazo es una decision de producto: en un
     * consultorio el mapa siempre esta, y obligar a que alguien lo cree a
     * proposito solo produce mapas que nunca se crean.
     */
    public function show(Paciente $paciente): JsonResponse
    {
        $this->authorize('verEstadoActual', $paciente);

        $creado = false;

        if (! $paciente->odontograma()->exists()) {
            if (! (bool) config('clinica-dental.odontograma.permitir_alta_automatica', true)) {
                return response()->json([
                    'mensaje' => 'Este paciente todavia no tiene odontograma registrado.',
                    'tiene_odontograma' => false,
                ], 404);
            }

            $this->servicio->crearOdontograma(
                $paciente,
                (string) config('clinica-dental.odontograma.origen_por_defecto', 'nuevo'),
            );

            $creado = true;
        }

        $odontograma = $this->servicio->odontogramaDe($paciente);

        $this->authorize('view', $odontograma);

        $capacidades = $this->capacidades();
        $conNotas = $capacidades->puedeVerCronologia();

        $odontograma->load(['piezas' => fn ($consulta) => $consulta->orderBy('numero_fdi')]);

        return response()->json([
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombreCompleto(),
            ],
            'odontograma' => [
                'id' => $odontograma->id,
                'fecha_alta' => $odontograma->fecha_alta,
                'origen' => $odontograma->origen,
                'ultima_intervencion_at' => $odontograma->ultima_intervencion_at?->toIso8601String(),
            ],
            'creado_al_consultar' => $creado,
            'incluye_notas' => $conNotas,
            'piezas' => $odontograma->piezas->map(
                static fn ($pieza): array => [
                    'numero_fdi' => $pieza->numero_fdi,
                    'nombre' => PiezaFdi::formatear($pieza->numero_fdi),
                    'arcada' => PiezaFdi::arcada($pieza->numero_fdi),
                    'lado' => PiezaFdi::lado($pieza->numero_fdi),
                    'estado' => $pieza->estado->value,
                    'estado_etiqueta' => $pieza->estado->etiqueta(),
                    'abreviatura' => $pieza->estado->abreviatura(),
                    'estado_desde' => $pieza->estado_desde?->toDateString(),
                    'dias_en_estado' => $pieza->diasDesdeElUltimoCambio(),
                    // Las notas van solo si el permiso las abre. El campo
                    // aparece en null en vez de desaparecer, para que la
                    // interfaz no tenga que adivinar.
                    'notas' => $conNotas ? $pieza->notas : null,
                ],
            )->all(),
            'leyenda' => $this->leyenda(),
        ]);
    }

    /**
     * Alta manual del mapa, para cuando se quiere dejar constancia de que la
     * fecha de alta es de hoy y no del momento en que alguien miró.
     */
    public function store(Request $peticion, Paciente $paciente): JsonResponse
    {
        $this->authorize('create', $paciente);

        $datos = $peticion->validate([
            'origen' => ['nullable', 'string', 'max:32'],
            'fecha_alta' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $odontograma = $this->servicio->crearOdontograma(
            $paciente,
            $datos['origen'] ?? (string) config('clinica-dental.odontograma.origen_por_defecto', 'nuevo'),
            isset($datos['fecha_alta']) ? Carbon::parse($datos['fecha_alta']) : null,
        );

        return response()->json([
            'mensaje' => 'Odontograma registrado.',
            'odontograma' => [
                'id' => $odontograma->id,
                'fecha_alta' => $odontograma->fecha_alta,
                'origen' => $odontograma->origen,
            ],
        ], 201);
    }

    /**
     * Los estados posibles y su abreviatura, para el mapa.
     *
     * Se devuelve con la respuesta para que la interfaz no tenga que duplicar
     * la tabla de colores y de simbolos. Duplicarla es como las dos versiones
     * dejan de coincidir.
     *
     * @return list<array<string, mixed>>
     */
    private function leyenda(): array
    {
        $salida = [];

        foreach (EstadoPieza::cases() as $estado) {
            $salida[] = [
                'valor' => $estado->value,
                'etiqueta' => $estado->etiqueta(),
                'abreviatura' => $estado->abreviatura(),
                'grupo' => $estado->grupo(),
                'esta_fuera_de_la_boca' => $estado->estaFueraDeLaBoca(),
                'es_reemplazo' => $estado->esReemplazo(),
            ];
        }

        return $salida;
    }
}