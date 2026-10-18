<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Procedimientos del catalogo.
 *
 * Un procedimiento es lo que se le hace a una pieza o a un paciente: limpieza,
 * obturacion, extraccion. De el sale la DURACION de la cita, que es la unica
 * razon por la que este catalogo es tan sensible como es.
 *
 * LA DURACION NO LA ESCRIBE EL USUARIO AL AGENDAR
 * -----------------------------------------------
 * Sale de aqui. Si el cliente mandara su propia duracion al agendar, podria
 * solapar dos citas escribiendo en `piezas` lo que quisiera. Ver
 * ServicioAgenda::agendar.
 *
 * `es_quirurgico` es un dato que el consultorio declara. NO lo deduce el
 * sistema ni se valida contra ningun catalogo oficial: es una clasificacion
 * del consultorio, y por eso queda como texto controlado, no como dato
 * verificado. Ver docs/10.
 */
class ProcedimientoController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Procedimiento::class);

        $datos = $peticion->validate([
            'buscar' => ['nullable', 'string', 'max:60'],
            'categoria' => ['nullable', 'string', 'max:60'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $procedimientos = Procedimiento::query()
            ->where('tenant_id', $this->consultorioId())
            ->when(isset($datos['buscar']), function ($consulta) use ($datos): void {
                $texto = '%' . str_replace(['%', '_'], ['\%', '\_'], $datos['buscar']) . '%';

                $consulta->where(function ($interna) use ($texto): void {
                    $interna->where('nombre', 'ilike', $texto)
                        ->orWhere('codigo', 'ilike', $texto);
                });
            })
            ->when(isset($datos['categoria']), fn ($c) => $c->where('categoria', $datos['categoria']))
            ->when($peticion->has('activo'), fn ($c) => $c->where('activo', $peticion->boolean('activo')))
            ->orderBy('categoria')
            ->orderBy('nombre')
            ->get();

        $hoy = now()->toDateString();

        return response()->json([
            'total' => $procedimientos->count(),
            'categorias' => Procedimiento::query()
                ->where('tenant_id', $this->consultorioId())
                ->distinct()
                ->orderBy('categoria')
                ->pluck('categoria'),
            'datos' => $procedimientos->map(
                static fn (Procedimiento $p): array => [
                    'id' => $p->id,
                    'codigo' => $p->codigo,
                    'nombre' => $p->nombre,
                    'categoria' => $p->categoria,
                    'duracion_minutos' => $p->duracion_minutos,
                    'es_quirurgico' => $p->es_quirurgico,
                    'requiere_anamnesis' => $p->requiere_anamnesis,
                    'descripcion' => $p->descripcion,
                    'activo' => $p->activo,
                    'precio_vigente' => $p->tarifaVigente($hoy)?->precio_clp,
                ],
            )->all(),
        ]);
    }

    public function show(Procedimiento $procedimiento): JsonResponse
    {
        $this->authorize('view', $procedimiento);

        $hoy = now()->toDateString();

        return response()->json([
            'id' => $procedimiento->id,
            'codigo' => $procedimiento->codigo,
            'nombre' => $procedimiento->nombre,
            'categoria' => $procedimiento->categoria,
            'duracion_minutos' => $procedimiento->duracion_minutos,
            'es_quirurgico' => $procedimiento->es_quirurgico,
            'requiere_anamnesis' => $procedimiento->requiere_anamnesis,
            'descripcion' => $procedimiento->descripcion,
            'activo' => $procedimiento->activo,
            'tarifa_vigente' => $procedimiento->tarifaVigente($hoy)?->precio_clp,
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Procedimiento::class);

        $datos = $peticion->validate([
            'codigo' => ['required', 'string', 'max:24'],
            'nombre' => ['required', 'string', 'max:120'],
            'categoria' => ['nullable', 'string', 'max:60'],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:240'],
            'es_quirurgico' => ['nullable', 'boolean'],
            'requiere_anamnesis' => ['nullable', 'boolean'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
        ]);

        $consultorioId = $this->consultorioId();

        $repetido = Procedimiento::query()
            ->where('tenant_id', $consultorioId)
            ->where('codigo', $datos['codigo'])
            ->exists();

        if ($repetido) {
            return response()->json([
                'mensaje' => 'Ya existe un procedimiento con ese codigo en este consultorio.',
                'errores' => ['codigo' => ['Ya existe un procedimiento con ese codigo en este consultorio.']],
            ], 422);
        }

        $procedimiento = Procedimiento::query()->create(array_merge($datos, [
            'tenant_id' => $consultorioId,
        ]));

        return response()->json(['mensaje' => 'Procedimiento registrado.', 'id' => $procedimiento->id], 201);
    }

    public function update(Request $peticion, Procedimiento $procedimiento): JsonResponse
    {
        $this->authorize('update', $procedimiento);

        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'string', 'max:120'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:60'],
            // La duracion se puede cambiar, pero hay que entender que afecta a
            // las citas futuras, no a las ya agendadas: cada cita guarda la
            // duracion con la que se creo (`citas.duracion_minutos`).
            'duracion_minutos' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'es_quirurgico' => ['sometimes', 'boolean'],
            'requiere_anamnesis' => ['sometimes', 'boolean'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $procedimiento->fill($datos);
        $procedimiento->save();

        return response()->json(['mensaje' => 'Procedimiento actualizado.', 'id' => $procedimiento->id]);
    }
}
