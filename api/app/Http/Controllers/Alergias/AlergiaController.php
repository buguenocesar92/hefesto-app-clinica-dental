<?php

declare(strict_types=1);

namespace App\Http\Controllers\Alergias;

use App\Enums\SeveridadAlergia;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarAlergiaRequest;
use App\Models\Alergia;
use App\Models\Paciente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alergias del paciente.
 *
 * LA REGLA DE ORO DE ESTA APLICACION ESTA EN ESTE ARCHIVO
 * ------------------------------------------------------
 * Una alergia activa sin tratar es una de las causas mas comunes de un
 * accidente en el sillon. Por eso el sistema no se limita a mostrar una lista:
 *
 *   - Recepcion las VE y las ve antes de agendar (AgendaController).
 *   - Agendar sobre una alergia activa esta BLOQUEADO por
 *     `RevisorDeObstaclos`, y el bloqueo se produce antes que la comprobacion
 *     de horario, porque es el unico que no se resuelve cambiando de hora.
 *   - El bloqueo solo se levanta con un desbloqueo FIRMADO por un profesional,
 *     con motivo escrito.
 *
 * LO QUE NO HACE EL SISTEMA
 * -------------------------
 * No calcule la severidad ni deducia si una alergia bloquea. Esos son criterios
 * clinicos, y adivinar seria peor que preguntar: un falso positivo detiene
 * pacientes que si se pueden atender. Ver GuardarAlergiaRequest.
 *
 * UNA ALERGIA NO SE BORRA
 * -----------------------
 * Se cierra con fecha y motivo. El paciente sigue siendo alergico dentro de
 * tres años y va a volver. Ver AlergiaPolicy::delete.
 */
class AlergiaController extends Controller
{
    /**
     * Historial completo de alergias del paciente: activas y cerradas.
     *
     * No solo las activas: ver cuando se cerro una alergia y por que es parte
     * de la historia clinica, y es la informacion que evita que un
     * profesional la vuelva a registrar sin saber que ya se evaluó.
     */
    public function index(Paciente $paciente): JsonResponse
    {
        $this->authorize('verAlergias', $paciente);

        $consultorioId = $this->consultorioId();

        $alergias = Alergia::query()
            ->where('tenant_id', $consultorioId)
            ->where('paciente_id', $paciente->id)
            ->with('profesional:id,tenant_id,nombre')
            ->orderByDesc('activa')
            ->orderByDesc('fecha_registro')
            ->get();

        return response()->json([
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombreCompleto(),
            ],
            'activas' => $alergias->where('activa', true)->count(),
            'cerradas' => $alergias->where('activa', false)->count(),
            'datos' => $alergias->map(
                static fn (Alergia $alergia): array => [
                    'id' => $alergia->id,
                    'sustancia' => $alergia->sustancia,
                    'reaccion' => $alergia->reaccion,
                    'severidad' => $alergia->severidad->value,
                    'severidad_etiqueta' => $alergia->severidad->etiqueta(),
                    'puede_ser_fatal' => $alergia->severidad->puedeSerFatal(),
                    'activa' => $alergia->activa,
                    'fecha_registro' => $alergia->fecha_registro?->toDateString(),
                    'fecha_cierre' => $alergia->fecha_cierre?->toDateString(),
                    'motivo_cierre' => $alergia->motivo_cierre,
                    'notas' => $alergia->notas,
                    'registrada_por' => $alergia->profesional?->nombre,
                    'linea_de_bloqueo' => $alergia->activa ? $alergia->lineaDeBloqueo() : null,
                ],
            )->all(),
        ]);
    }

    /**
     * Registrar una alergia.
     *
     * La `severidad` la pone el profesional que evaluó al paciente. El sistema
     * la usa para ordenar la ficha y para pedir mas cuidado en los casos
     * graves, pero no la inventa.
     */
    public function store(GuardarAlergiaRequest $peticion, Paciente $paciente): JsonResponse
    {
        $this->authorize('create', Alergia::class);

        $datos = $peticion->validated();

        $alergia = Alergia::query()->create(array_merge($datos, [
            'tenant_id' => $this->consultorioId(),
            'paciente_id' => $paciente->id,
            'profesional_id' => $this->usuario()->profesional_id,
            'activa' => true,
            'fecha_registro' => $datos['fecha_registro'] ?? now()->toDateString(),
        ]));

        return response()->json([
            'mensaje' => 'Alergia registrada. Bloquea la atencion hasta que se registre un desbloqueo.',
            'alergia' => [
                'id' => $alergia->id,
                'sustancia' => $alergia->sustancia,
                'severidad' => $alergia->severidad->value,
                'linea_de_bloqueo' => $alergia->lineaDeBloqueo(),
            ],
        ], 201);
    }

    public function update(GuardarAlergiaRequest $peticion, Alergia $alergia): JsonResponse
    {
        $this->authorize('update', $alergia);

        $datos = $peticion->validated();

        // La `activa` NO se edita desde aca. Una alergia deja de estar activa
        // cerrandose con fecha y motivo (`cerrar`), no editando una fila: la
        // diferencia es la que permite que el motivo quede.
        unset($datos['activa']);

        $alergia->fill($datos);
        $alergia->save();

        return response()->json(['mensaje' => 'Alergia actualizada.', 'id' => $alergia->id]);
    }

    /**
     * Cerrar una alergia.
     *
     * Ruta aparte de la edicion, y no por estetica: cerrar es una decision
     * clinica que deja constancia, y por eso exige motivo.
     */
    public function cerrar(Request $peticion, Alergia $alergia): JsonResponse
    {
        $this->authorize('cerrar', $alergia);

        if (! $alergia->activa) {
            return response()->json(['mensaje' => 'Esta alergia ya estaba cerrada.'], 422);
        }

        $datos = $peticion->validate([
            'motivo_cierre' => ['required', 'string', 'min:10', 'max:240'],
            'fecha_cierre' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $alergia->forceFill([
            'activa' => false,
            'fecha_cierre' => $datos['fecha_cierre'] ?? now()->toDateString(),
            'motivo_cierre' => $datos['motivo_cierre'],
        ])->save();

        return response()->json([
            'mensaje' => 'Alergia cerrada. El registro se conserva.',
            'id' => $alergia->id,
            'fecha_cierre' => $alergia->fecha_cierre?->toDateString(),
        ]);
    }

    /**
     * Escala de severidad, con la urgencia que cada una implica.
     *
     * Se expone para que la interfaz ordene igual que el servidor, y sobre
     * todo para que quede escrito que la URGENCIA la define un profesional y no
     * una regla del sistema. Ver docs/07.
     */
    public function escala(): JsonResponse
    {
        return response()->json([
            'escala' => array_map(static fn (SeveridadAlergia $severidad): array => [
                'valor' => $severidad->value,
                'etiqueta' => $severidad->etiqueta(),
                'puede_ser_fatal' => $severidad->puedeSerFatal(),
                'urgencia' => $severidad->urgencia(),
            ], SeveridadAlergia::cases()),
            'nota' => 'La severidad la evalua el profesional que examina al paciente. '
                . 'El sistema la ordena y la muestra, pero no la deduce.',
        ]);
    }
}