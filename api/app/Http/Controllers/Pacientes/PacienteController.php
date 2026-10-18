<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pacientes;

use App\Enums\Sexo;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPacienteRequest;
use App\Models\Paciente;
use App\Services\Pacientes\TiempoSinControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ficha de identificacion de pacientes.
 *
 * ACESIBLE PARA LOS TRES ROLES. Aqui esta el otro lado del producto: recepcion
 * necesita saber a quien agenda, con que telefono y con que prevision. Eso es
 * dato personal, no dato de salud.
 *
 * El historial odontologico de estos mismos pacientes vive en otras rutas
 * (`pacientes/{paciente}/odontograma`, `.../cronologia`), que exigen rol
 * profesional y ademas quedan auditadas. Ver docs/03.
 *
 * LO QUE NO SE PONE EN ESTA FICHA
 * ------------------------------
 * `notas_administrativas` es el unico campo de texto libre, y a proposito es
 * corto (mil caracteres). Las notas largas son donde se cuelan datos clinicos
 * sin permiso y sin auditoria. Si hace falta una nota clinica, va en la
 * cronologia de una pieza. Ver GuardarPacienteRequest.
 */
class PacienteController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Paciente::class);

        $datos = $peticion->validate([
            'buscar' => ['nullable', 'string', 'max:80'],
            'activo' => ['nullable', 'boolean'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $porPagina = (int) ($datos['por_pagina'] ?? 25);

        $consultorioId = $this->consultorioId();

        $pacientes = Paciente::query()
            ->where('tenant_id', $consultorioId)
            ->when(isset($datos['buscar']), function ($consulta) use ($datos): void {
                $texto = '%' . str_replace(['%', '_'], ['\%', '\_'], $datos['buscar']) . '%';

                $consulta->where(function ($interna) use ($texto): void {
                    $interna->where('nombres', 'ilike', $texto)
                        ->orWhere('apellidos', 'ilike', $texto)
                        ->orWhere('rut', 'ilike', $texto);
                });
            })
            ->when($peticion->has('activo'), fn ($consulta) => $consulta->where('activo', $peticion->boolean('activo')))
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->paginate($porPagina);

        return response()->json([
            'total' => $pacientes->total(),
            'pagina_actual' => $pacientes->currentPage(),
            'por_pagina' => $pacientes->perPage(),
            'datos' => $pacientes->getCollection()->map(
                static fn (Paciente $paciente): array => [
                    'id' => $paciente->id,
                    'rut' => $paciente->rut,
                    'nombre' => $paciente->nombreCompleto(),
                    'nombres' => $paciente->nombres,
                    'apellidos' => $paciente->apellidos,
                    'edad' => $paciente->edad(),
                    'telefono' => $paciente->telefono,
                    'activo' => $paciente->activo,
                ],
            )->all(),
        ]);
    }

    public function show(Paciente $paciente): JsonResponse
    {
        $this->authorize('view', $paciente);

        $tiempo = new TiempoSinControl($paciente);

        return response()->json([
            'id' => $paciente->id,
            'rut' => $paciente->rut,
            'nombres' => $paciente->nombres,
            'apellidos' => $paciente->apellidos,
            'nombre' => $paciente->nombreCompleto(),
            'fecha_nacimiento' => $paciente->fecha_nacimiento?->toDateString(),
            'edad' => $paciente->edad(),
            'sexo' => $paciente->sexo->value,
            'sexo_etiqueta' => $paciente->sexo->etiqueta(),
            'telefono' => $paciente->telefono,
            'correo' => $paciente->correo,
            'direccion' => $paciente->direccion,
            'prevision' => $paciente->prevision,
            'notas_administrativas' => $paciente->notas_administrativas,
            'activo' => $paciente->activo,
            // Cuenta de agenda, que es dato operativo y lo necesita cualquiera.
            'citas_totales' => $paciente->citas()->count(),
            'tiene_odontograma' => $paciente->odontograma()->exists(),
            // Aviso de control periodico. No es un dato clinico: es "cuando
            // fue la ultima vez que lo vimos", que es lo que permite que
            // recepcion llame al paciente para la profilaxis.
            'ultima_atencion' => $tiempo->descripcion(),
            'dias_sin_control' => $tiempo->dias(),
        ]);
    }

    public function store(GuardarPacienteRequest $peticion): JsonResponse
    {
        $this->authorize('create', Paciente::class);

        $datos = $peticion->datos();
        $consultorioId = $this->consultorioId();

        if (isset($datos['rut']) && Paciente::query()
            ->where('tenant_id', $consultorioId)
            ->where('rut', $datos['rut'])
            ->exists()
        ) {
            return $this->errorRut('Ya existe un paciente con ese RUT en este consultorio.');
        }

        $paciente = Paciente::query()->create(array_merge($datos, [
            'tenant_id' => $consultorioId,
            'sexo' => $datos['sexo'] ?? Sexo::NoInformado->value,
        ]));

        return response()->json([
            'mensaje' => 'Paciente registrado.',
            'paciente' => ['id' => $paciente->id, 'nombre' => $paciente->nombreCompleto()],
        ], 201);
    }

    public function update(GuardarPacienteRequest $peticion, Paciente $paciente): JsonResponse
    {
        $this->authorize('update', $paciente);

        $datos = $peticion->datos();
        $consultorioId = $this->consultorioId();

        if (isset($datos['rut'])) {
            $repetido = Paciente::query()
                ->where('tenant_id', $consultorioId)
                ->where('rut', $datos['rut'])
                ->whereKeyNot($paciente->id)
                ->exists();

            if ($repetido) {
                return $this->errorRut('Ya existe otro paciente con ese RUT en este consultorio.');
            }
        }

        $paciente->fill($datos);
        $paciente->save();

        return response()->json([
            'mensaje' => 'Paciente actualizado.',
            'id' => $paciente->id,
        ]);
    }

    /**
     * Dar de baja. No borrar.
     *
     * Un paciente que attended la semana pasada tiene historia clinica. Sacarlo
     * de la base seria borrar el unico registro de lo que se le hizo en las
     * piezas. La baja deja de aparecer en el buscador de agenda y conserva
     * todo lo demas. Ver PacientePolicy::delete.
     */
    public function destroy(Paciente $paciente): JsonResponse
    {
        $this->authorize('update', $paciente);

        $paciente->forceFill(['activo' => false])->save();

        return response()->json([
            'mensaje' => 'Paciente dado de baja. Su historial se conserva.',
            'id' => $paciente->id,
            'activo' => false,
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    private function errorRut(string $mensaje): JsonResponse
    {
        return response()->json([
            'mensaje' => $mensaje,
            'errores' => ['rut' => [$mensaje]],
        ], 422);
    }
}