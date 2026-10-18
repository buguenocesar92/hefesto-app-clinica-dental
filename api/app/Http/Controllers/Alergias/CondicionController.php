<?php

declare(strict_types=1);

namespace App\Http\Controllers\Alergias;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarCondicionRequest;
use App\Models\Condicion;
use App\Models\Paciente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Condiciones de salud del paciente.
 *
 * Son el segundo tipo de antecedente que puede impedir una atencion, y siguen
 * las mismas reglas que las alergias: las ve todo el mundo, las registra el
 * profesional, y no se borran sino que se cierran con motivo.
 *
 * LA DIFERENCIA CON UNA ALERGIA
 * ----------------------------
 * Una alergia tiene una sustancia y una reaccion. Una condicion es mas general:
 * puede ser una enfermedad de base, un tratamiento en curso o una situacion
 * particular de la consulta. El campo `bloquea_atencion` lo marca el profesional y el sistema no
 * lo deduce. Ver GuardarCondicionRequest.
 */
class CondicionController extends Controller
{
    public function index(Paciente $paciente): JsonResponse
    {
        $this->authorize('verAlergias', $paciente);

        $condiciones = Condicion::query()
            ->where('tenant_id', $this->consultorioId())
            ->where('paciente_id', $paciente->id)
            ->with('profesional:id,tenant_id,nombre')
            ->orderByDesc('bloquea_atencion')
            ->orderByDesc('activa')
            ->orderByDesc('fecha_registro')
            ->get();

        return response()->json([
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombreCompleto(),
            ],
            'bloqueantes_activas' => $condiciones
                ->filter(static fn (Condicion $c): bool => $c->activa && $c->bloquea_atencion)
                ->count(),
            'datos' => $condiciones->map(
                static fn (Condicion $condicion): array => [
                    'id' => $condicion->id,
                    'condicion' => $condicion->condicion,
                    'detalle' => $condicion->detalle,
                    'bloquea_atencion' => $condicion->bloquea_atencion,
                    'requiere_autorizacion_especial' => $condicion->requiere_autorizacion_especial,
                    'referencia_documento' => $condicion->referencia_documento,
                    'activa' => $condicion->activa,
                    'fecha_registro' => $condicion->fecha_registro?->toDateString(),
                    'fecha_cierre' => $condicion->fecha_cierre?->toDateString(),
                    'motivo_cierre' => $condicion->motivo_cierre,
                    'registrada_por' => $condicion->profesional?->nombre,
                ],
            )->all(),
        ]);
    }

    public function store(GuardarCondicionRequest $peticion, Paciente $paciente): JsonResponse
    {
        $this->authorize('create', Condicion::class);

        $datos = $peticion->validated();

        $condicion = Condicion::query()->create(array_merge($datos, [
            'tenant_id' => $this->consultorioId(),
            'paciente_id' => $paciente->id,
            'profesional_id' => $this->usuario()->profesional_id,
            'activa' => true,
            'fecha_registro' => $datos['fecha_registro'] ?? now()->toDateString(),
        ]));

        return response()->json([
            'mensaje' => $condicion->bloquea_atencion
                ? 'Condicion registrada. Bloquea la atencion hasta que se registre un desbloqueo.'
                : 'Condicion registrada.',
            'id' => $condicion->id,
            'bloquea_atencion' => $condicion->bloquea_atencion,
        ], 201);
    }

    public function update(GuardarCondicionRequest $peticion, Condicion $condicion): JsonResponse
    {
        $this->authorize('update', $condicion);

        $datos = $peticion->validated();
        unset($datos['activa']);

        $condicion->fill($datos);
        $condicion->save();

        return response()->json(['mensaje' => 'Condicion actualizada.', 'id' => $condicion->id]);
    }

    /**
     * Cerrar una condicion. No borrar: el motivo queda.
     */
    public function cerrar(Request $peticion, Condicion $condicion): JsonResponse
    {
        $this->authorize('cerrar', $condicion);

        if (! $condicion->activa) {
            return response()->json(['mensaje' => 'Esta condicion ya estaba cerrada.'], 422);
        }

        $datos = $peticion->validate([
            'motivo_cierre' => ['required', 'string', 'min:10', 'max:240'],
            'fecha_cierre' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $condicion->forceFill([
            'activa' => false,
            'fecha_cierre' => $datos['fecha_cierre'] ?? now()->toDateString(),
            'motivo_cierre' => $datos['motivo_cierre'],
        ])->save();

        return response()->json([
            'mensaje' => 'Condicion cerrada. El registro se conserva.',
            'id' => $condicion->id,
        ]);
    }
}