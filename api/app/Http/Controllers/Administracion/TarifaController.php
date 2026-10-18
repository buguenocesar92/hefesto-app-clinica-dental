<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Procedimiento;
use App\Models\Tarifa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tarifario del consultorio.
 *
 * UNA TARIFA ES UN DATO, NO UN COBRO
 * ---------------------------------
 * El precio de un procedimiento vive aca. La facturacion NO esta
 * implementada: no hay cobro, ni boleta, ni conexion con el sistema de
 * facturacion de Hefesto. Queda anotado en docs/10.
 *
 * LA VIGENCIA ES POR FECHA, NO POR EDICION
 * ----------------------------------------
 * `vigente_desde` permite tener varias tarifas del mismo procedimiento con
 * fechas distintas, que es como funcionan las listas de precios en la practica.
 * La vigente es la mas reciente cuyo `vigente_desde` no sea posterior al dia
 * pedido. Ver Procedimiento::tarifaVigente.
 */
class TarifaController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Tarifa::class);

        $datos = $peticion->validate([
            'procedimiento_id' => ['nullable', 'uuid'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $tarifas = Tarifa::query()
            ->where('tenant_id', $this->consultorioId())
            ->with('procedimiento:id,tenant_id,codigo,nombre')
            ->when(isset($datos['procedimiento_id']), fn ($c) => $c->where('procedimiento_id', $datos['procedimiento_id']))
            ->when($peticion->has('activo'), fn ($c) => $c->where('activo', $peticion->boolean('activo')))
            ->orderByDesc('vigente_desde')
            ->get();

        $hoy = now()->toDateString();

        return response()->json([
            'total' => $tarifas->count(),
            'fecha_de_corte' => $hoy,
            'datos' => $tarifas->map(
                static fn (Tarifa $tarifa): array => [
                    'id' => $tarifa->id,
                    'nombre' => $tarifa->nombre,
                    'precio_clp' => $tarifa->precio_clp,
                    'vigente_desde' => $tarifa->vigente_desde,
                    'activo' => $tarifa->activo,
                    'es_la_vigente' => $tarifa->activo
                        && $tarifa->vigente_desde !== null
                        && $tarifa->vigente_desde->toDateString() <= $hoy,
                    'procedimiento' => $tarifa->procedimiento === null ? null : [
                        'id' => $tarifa->procedimiento->id,
                        'codigo' => $tarifa->procedimiento->codigo,
                        'nombre' => $tarifa->procedimiento->nombre,
                    ],
                ],
            )->all(),
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Tarifa::class);

        $datos = $peticion->validate([
            'procedimiento_id' => ['required', 'uuid'],
            'nombre' => ['required', 'string', 'max:120'],
            'precio_clp' => ['required', 'integer', 'min:0'],
            'vigente_desde' => ['required', 'date'],
        ]);

        $consultorioId = $this->consultorioId();

        if (! Procedimiento::query()
            ->where('tenant_id', $consultorioId)
            ->whereKey($datos['procedimiento_id'])
            ->exists()
        ) {
            return response()->json(['mensaje' => 'El procedimiento no existe en este consultorio.'], 422);
        }

        $tarifa = Tarifa::query()->create(array_merge($datos, [
            'tenant_id' => $consultorioId,
        ]));

        return response()->json(['mensaje' => 'Tarifa registrada.', 'id' => $tarifa->id], 201);
    }

    public function update(Request $peticion, Tarifa $tarifa): JsonResponse
    {
        $this->authorize('update', $tarifa);

        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'string', 'max:120'],
            'precio_clp' => ['sometimes', 'integer', 'min:0'],
            'vigente_desde' => ['sometimes', 'date'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $tarifa->fill($datos);
        $tarifa->save();

        return response()->json(['mensaje' => 'Tarifa actualizada.', 'id' => $tarifa->id]);
    }
}