<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profesionales del consultorio.
 *
 * Un profesional es quien atiende. La ficha es corta a proposito: nombre,
 * especialidad y registro profesional. No hay informacion de salud de un
 * profesional en este sistema: la que importa es la de los pacientes que
 * atiende, y esa vive en el odontograma del paciente, con su propia auditoria.
 *
 * `registro_profesional` es TEXTO LIBRE y no se valida contra ninguna fuente
 * oficial. Queda anotado como pendiente en docs/07 y docs/10: un registro sin
 * verificar es un dato, no una garantia.
 */
class ProfesionalController extends Controller
{
    /**
     * Listado de administracion, con el detalle completo.
     */
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Profesional::class);

        $datos = $peticion->validate([
            'buscar' => ['nullable', 'string', 'max:80'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $profesionales = Profesional::query()
            ->where('tenant_id', $this->consultorioId())
            ->when(isset($datos['buscar']), function ($consulta) use ($datos): void {
                $texto = '%' . str_replace(['%', '_'], ['\%', '\_'], $datos['buscar']) . '%';

                $consulta->where(function ($interna) use ($texto): void {
                    $interna->where('nombre', 'ilike', $texto)
                        ->orWhere('especialidad', 'ilike', $texto);
                });
            })
            ->when($peticion->has('activo'), fn ($consulta) => $consulta->where('activo', $peticion->boolean('activo')))
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'total' => $profesionales->count(),
            'datos' => $profesionales->map($this->fila(...))->all(),
        ]);
    }

    /**
     * Listado para el selector de la agenda.
     *
     * Ruta aparte, con permiso aparte (`verParaAgenda`), porque la agenda la
     * usan los tres roles y la administracion es del administrador. Abrir el
     * detalle de un profesional (correo, telefono, registro) no es lo mismo que
     * poder elegir a quien se agenda.
     */
    public function paraAgenda(): JsonResponse
    {
        $this->authorize('verParaAgenda', Profesional::class);

        $profesionales = Profesional::query()
            ->where('tenant_id', $this->consultorioId())
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'tenant_id', 'nombre', 'especialidad', 'color_agenda']);

        return response()->json([
            'datos' => $profesionales->map(static fn (Profesional $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'especialidad' => $p->especialidad,
                'color_agenda' => $p->color_agenda,
            ])->all(),
        ]);
    }

    public function show(Profesional $profesional): JsonResponse
    {
        $this->authorize('view', $profesional);

        return response()->json(['profesional' => $this->fila($profesional)]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Profesional::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:160'],
            'especialidad' => ['nullable', 'string', 'max:120'],
            'registro_profesional' => ['nullable', 'string', 'max:60'],
            'correo' => ['nullable', 'email:rfc', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'color_agenda' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $profesional = Profesional::query()->create(array_merge($datos, [
            'tenant_id' => $this->consultorioId(),
        ]));

        return response()->json([
            'mensaje' => 'Profesional registrado.',
            'id' => $profesional->id,
        ], 201);
    }

    public function update(Request $peticion, Profesional $profesional): JsonResponse
    {
        $this->authorize('update', $profesional);

        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'string', 'min:3', 'max:160'],
            'especialidad' => ['sometimes', 'nullable', 'string', 'max:120'],
            'registro_profesional' => ['sometimes', 'nullable', 'string', 'max:60'],
            'correo' => ['sometimes', 'nullable', 'email:rfc', 'max:160'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:32'],
            'color_agenda' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // Se desactiva, no se borra: sus citas y sus intervenciones quedan
            // en la historia clinica con su nombre. Ver ProfesionalPolicy.
            'activo' => ['sometimes', 'boolean'],
        ]);

        $profesional->fill($datos);
        $profesional->save();

        return response()->json(['mensaje' => 'Profesional actualizado.', 'id' => $profesional->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fila(Profesional $profesional): array
    {
        return [
            'id' => $profesional->id,
            'nombre' => $profesional->nombre,
            'especialidad' => $profesional->especialidad,
            'registro_profesional' => $profesional->registro_profesional,
            'registro_verificado' => false,
            'correo' => $profesional->correo,
            'telefono' => $profesional->telefono,
            'color_agenda' => $profesional->color_agenda,
            'activo' => $profesional->activo,
            'citas_totales' => $profesional->citas()->count(),
            'tiene_cuentas' => $profesional->usuarios()->count() > 0,
        ];
    }
}