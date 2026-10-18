<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Insumo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Insumos del consultorio.
 *
 * Un insumo es material que se consume: el stock baja con cada atencion y hay
 * que saber cuando reponer. Se administra desde el rol `admin`, que cuelga del
 * middleware y de la Policy: las dos capas.
 *
 * LO QUE NO HACE
 * ---------------
 * No descuenta stock solo. El consumo real se debería mover desde el registro
 * de la intervencion, y eso NO esta implementado: hoy `stock_actual` se ajusta
 * a mano. Queda anotado como pendiente en docs/10. Un inventario que se
 * descuenta a mano se desincroniza, y por eso conviene saber que hoy es asi.
 *
 * El ajuste de stock SI tiene su propia ruta, aparte de la edicion: es un
 * movimiento, no un cambio de datos, y por eso exige motivo.
 */
class InsumoController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Insumo::class);

        $datos = $peticion->validate([
            'buscar' => ['nullable', 'string', 'max:60'],
            'activo' => ['nullable', 'boolean'],
            'bajo_stock' => ['nullable', 'boolean'],
        ]);

        $insumos = Insumo::query()
            ->where('tenant_id', $this->consultorioId())
            ->when(isset($datos['buscar']), function ($consulta) use ($datos): void {
                $texto = '%' . str_replace(['%', '_'], ['\%', '\_'], $datos['buscar']) . '%';

                $consulta->where(function ($interna) use ($texto): void {
                    $interna->where('nombre', 'ilike', $texto)
                        ->orWhere('codigo', 'ilike', $texto);
                });
            })
            ->when($peticion->has('activo'), fn ($consulta) => $consulta->where('activo', $peticion->boolean('activo')))
            ->orderBy('nombre')
            ->get();

        // El filtro de stock bajo se aplica en memoria a proposito: `stock_actual
        // < stock_minimo` no es indexable y el catalogo de insumos de un
        // consultorio cabe de sobra en una pantalla.
        if ($peticion->boolean('bajo_stock')) {
            $insumos = $insumos->filter(static fn (Insumo $insumo): bool => $insumo->bajoStock())->values();
        }

        return response()->json([
            'total' => $insumos->count(),
            'bajo_stock' => $insumos->filter(static fn (Insumo $i): bool => $i->bajoStock())->count(),
            'datos' => $insumos->map(
                static fn (Insumo $insumo): array => [
                    'id' => $insumo->id,
                    'codigo' => $insumo->codigo,
                    'nombre' => $insumo->nombre,
                    'unidad' => $insumo->unidad,
                    'stock_actual' => $insumo->stock_actual,
                    'stock_minimo' => $insumo->stock_minimo,
                    'bajo_stock' => $insumo->bajoStock(),
                    'fecha_ultima_carga' => $insumo->fecha_ultima_carga?->toDateString(),
                    'activo' => $insumo->activo,
                ],
            )->all(),
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Insumo::class);

        $datos = $peticion->validate([
            'codigo' => ['required', 'string', 'max:24'],
            'nombre' => ['required', 'string', 'max:120'],
            'unidad' => ['nullable', 'string', 'max:24'],
            'stock_actual' => ['nullable', 'integer', 'min:0'],
            'stock_minimo' => ['nullable', 'integer', 'min:0'],
        ]);

        $consultorioId = $this->consultorioId();

        $repetido = Insumo::query()
            ->where('tenant_id', $consultorioId)
            ->where('codigo', $datos['codigo'])
            ->exists();

        if ($repetido) {
            return response()->json([
                'mensaje' => 'Ya existe un insumo con ese codigo en este consultorio.',
                'errores' => ['codigo' => ['Ya existe un insumo con ese codigo en este consultorio.']],
            ], 422);
        }

        $insumo = Insumo::query()->create(array_merge($datos, ['tenant_id' => $consultorioId]));

        return response()->json(['mensaje' => 'Insumo registrado.', 'id' => $insumo->id], 201);
    }

    public function update(Request $peticion, Insumo $insumo): JsonResponse
    {
        $this->authorize('update', $insumo);

        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'string', 'max:120'],
            'unidad' => ['sometimes', 'nullable', 'string', 'max:24'],
            // El stock NO se edita desde aca: se ajusta con `ajustarStock`, que
            // exige motivo. Editar el stock sin rastro deja un inventario que
            // nadie sabe explicar.
            'stock_minimo' => ['sometimes', 'integer', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $insumo->fill($datos);
        $insumo->save();

        return response()->json(['mensaje' => 'Insumo actualizado.', 'id' => $insumo->id]);
    }

    /**
     * Ajuste de stock, con motivo.
     *
     * `cantidad` es la cantidad que SE SUMA. Puede ser negativa para dejar una
     * merma, y eso es justamente lo que necesita motivo: una resta sin
     * explicación es donde se esconden las diferencias de inventario.
     */
    public function ajustarStock(Request $peticion, Insumo $insumo): JsonResponse
    {
        $this->authorize('update', $insumo);

        $datos = $peticion->validate([
            'cantidad' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'min:10', 'max:240'],
        ]);

        $resultante = $insumo->stock_actual + $datos['cantidad'];

        if ($resultante < 0) {
            return response()->json([
                'mensaje' => sprintf(
                    'El ajuste dejaria el stock en %d, y no puede ser negativo.',
                    $resultante,
                ),
            ], 422);
        }

        $insumo->forceFill([
            'stock_actual' => $resultante,
            'fecha_ultima_carga' => $datos['cantidad'] > 0 ? now()->toDateString() : $insumo->fecha_ultima_carga,
        ])->save();

        return response()->json([
            'mensaje' => 'Stock ajustado.',
            'id' => $insumo->id,
            'stock_actual' => $insumo->stock_actual,
            'bajo_stock' => $insumo->bajoStock(),
            'motivo' => $datos['motivo'],
        ]);
    }
}