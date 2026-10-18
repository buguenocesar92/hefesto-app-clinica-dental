<?php

declare(strict_types=1);

namespace App\Http\Controllers\Odontograma;

use App\Enums\EstadoPieza;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarIntervencionRequest;
use App\Models\Odontograma;
use App\Models\Paciente;
use App\Models\PlanTratamiento;
use App\Models\Profesional;
use App\Models\Procedimiento;
use App\Services\Odontograma\MaquinaDeEstadosPieza;
use App\Services\Odontograma\PiezaFdi;
use App\Services\Odontograma\ServicioOdontograma;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Registro de intervenciones sobre una pieza.
 *
 * AQUI SE ESCRIBE LA HISTORIA CLÍNICA
 * -----------------------------------
 * Esta es la unica forma de cambiar el estado de una pieza, y es deliberado:
 * una intervencion es un HECHO con fecha, con profesional y con descripcion.
 * Editar el mapa seria mas facil de escribir y convertiria la historia clinica
 * en algo que se puede reescribir cuando alguien se equivoca. Aca la
 * equivocacion se corrige con una intervencion nueva que la explica.
 *
 * LO QUE HACE EL SERVICIO Y NO ESTE CONTROLADOR
 * ---------------------------------------------
 * `ServicioOdontograma::registrar()` corre lo siguiente, en una sola
 * transaccion:
 *
 *   1. Valida el numero FDI.
 *   2. Exige que la transicion de estado sea legitima
 *      (`MaquinaDeEstadosPieza`).
 *   3. Inserta la intervencion, con estado anterior y resultante.
 *   4. Mueve la pieza y sella `ultima_intervencion_id`.
 *
 * El paso 4 lo comprueba un disparador de la base
 * (`piezas_exigen_cronologia`, migracion 000021): si alguien escribiera en
 * `piezas` desde fuera de este camino, la base lo rechaza. La inmutabilidad no
 * depende de que la aplicacion se porte bien.
 *
 * LA FECHA PUEDE SER PASADA
 * ------------------------
 * Es lo que permite migrar un cuaderno de papel sin perder las fechas de 2019.
 * `fecha_registro` no se pide nunca: lo pone el sistema, porque es el momento
 * en que alguien escribio el dato aca. Ver RegistrarIntervencionRequest.
 */
class IntervencionController extends Controller
{
    public function __construct(private readonly ServicioOdontograma $servicio) {}

    /**
     * Registrar una intervencion sobre una pieza.
     */
    public function store(RegistrarIntervencionRequest $peticion, Paciente $paciente): JsonResponse
    {
        $this->authorize('verEstadoActual', $paciente);

        if (! $paciente->odontograma()->exists()) {
            $this->servicio->crearOdontograma($paciente);
        }

        $odontograma = $this->servicio->odontogramaDe($paciente);

        // El permiso de ESCRIBIR es distinto del de VER. Ver el mapa no es lo
        // mismo que poder escribir en la historia clinica: el administrador ve
        // la cronologia pero no firma intervenciones.
        $this->authorize('registrarIntervencion', $odontograma);

        $datos = $peticion->validated();
        $consultorioId = $this->consultorioId();

        $usuario = $this->usuario();

        $profesional = Profesional::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($datos['profesional_id'])
            ->where('activo', true)
            ->first();

        if ($profesional === null) {
            return response()->json([
                'mensaje' => 'El profesional no existe o esta inactivo en este consultorio.',
            ], 422);
        }

        $estadoResultante = EstadoPieza::from($datos['estado_resultante']);

        $procedimiento = null;

        if (! empty($datos['procedimiento_id'])) {
            $procedimiento = Procedimiento::query()
                ->where('tenant_id', $consultorioId)
                ->whereKey($datos['procedimiento_id'])
                ->first();

            if ($procedimiento === null) {
                return response()->json([
                    'mensaje' => 'El procedimiento no existe en este consultorio.',
                ], 422);
            }
        }

        $plan = null;

        if (! empty($datos['plan_tratamiento_id'])) {
            $plan = PlanTratamiento::query()
                ->where('tenant_id', $consultorioId)
                ->whereKey($datos['plan_tratamiento_id'])
                ->where('paciente_id', $paciente->id)
                ->first();

            if ($plan === null) {
                return response()->json([
                    'mensaje' => 'El plan de tratamiento no existe en este consultorio o es de otro paciente.',
                ], 422);
            }
        }

        try {
            $intervencion = $this->servicio->registrar([
                'paciente' => $paciente,
                'numero_fdi' => (int) $datos['numero_fdi'],
                'estado_resultante' => $estadoResultante,
                'descripcion' => $datos['descripcion'],
                'profesional' => $profesional,
                'usuario' => $usuario,
                'fecha' => isset($datos['fecha']) ? Carbon::parse($datos['fecha']) : null,
                'procedimiento' => $procedimiento,
                'plan' => $plan,
                'notas' => $datos['notas'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            // Numero FDI invalido o transicion que no existe en odontologia.
            // El mensaje lo escribe la maquina de estados: es el unico lugar
            // que sabe que transiciones son legitimas. Ver docs/09.
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        $pieza = $this->servicio->piezaDe($odontograma, (int) $datos['numero_fdi']);

        return response()->json([
            'mensaje' => 'Intervencion registrada.',
            'intervencion' => [
                'id' => $intervencion->id,
                'numero_fdi' => $intervencion->numero_fdi,
                'estado_anterior' => $intervencion->estado_anterior,
                'estado_resultante' => $intervencion->estado_resultante,
                'fecha' => $intervencion->fecha?->toIso8601String(),
            ],
            'pieza' => [
                'estado' => $pieza->estado->value,
                'estado_etiqueta' => $pieza->estado->etiqueta(),
                'estado_desde' => $pieza->estado_desde?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Transiciones que la maquina de estados permite desde el estado actual de
     * una pieza.
     *
     * No es la tabla completa: son solo las salidas validas desde donde esta la
     * pieza ahora. Es lo que la interfaz necesita para no ofrecer un boton que
     * el servidor va a rechazar. La tabla completa esta en
     * `MaquinaDeEstadosPieza`, y el servidor la vuelve a comprobar igual.
     */
    public function transiciones(Paciente $paciente, Odontograma $odontograma, string $numeroFdi): JsonResponse
    {
        $this->authorize('view', $odontograma);

        $numero = (int) $numeroFdi;

        if (! PiezaFdi::esValido($numero)) {
            return response()->json(['mensaje' => 'El número de pieza no es válido.'], 422);
        }

        $pieza = $this->servicio->piezaDe($odontograma, $numero);

        $permitidas = [];

        foreach (EstadoPieza::cases() as $estado) {
            if (MaquinaDeEstadosPieza::permite($pieza->estado, $estado)) {
                $permitidas[] = [
                    'valor' => $estado->value,
                    'etiqueta' => $estado->etiqueta(),
                    'abreviatura' => $estado->abreviatura(),
                ];
            }
        }

        return response()->json([
            'numero_fdi' => $numero,
            'estado_actual' => $pieza->estado->value,
            'transiciones_permitidas' => $permitidas,
        ]);
    }
}