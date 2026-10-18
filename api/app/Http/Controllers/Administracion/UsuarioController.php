<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuentas del consultorio.
 *
 * LO QUE ESTO NO ES
 * -----------------
 * No es un alta de usuarios. La IDENTIDAD la emite Authentik: aca no se crean
 * contrasenas ni se validan credenciales. Lo que se guarda es la copia de
 * trabajo (rol, ficha de profesional, activo), que se sincroniza en cada
 * ingreso a partir de los equipos de Authentik. Ver App\Services\Auth.
 *
 * POR QUE EXISTE ESTA PANTALLA
 * ---------------------------
 * Porque un administrador tiene que poder DESACTIVAR una cuenta dentro de la
 * aplicacion, sin esperar a que un administrador de identidad lo haga. Es una
 * medida de emergencia, no el mecanismo normal.
 *
 * Ver UsuarioPolicy y docs/05.
 */
class UsuarioController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Usuario::class);

        $usuarios = Usuario::query()
            ->where('tenant_id', $this->consultorioId())
            ->with('profesional:id,tenant_id,nombre,especialidad')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'total' => $usuarios->count(),
            'datos' => $usuarios->map(
                static fn (Usuario $usuario): array => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->nombre,
                    'correo' => $usuario->correo,
                    'rol' => $usuario->rol->value,
                    'rol_etiqueta' => $usuario->rol->etiqueta(),
                    'activo' => $usuario->activo,
                    'tiene_ficha_profesional' => $usuario->profesional_id !== null,
                    'ficha_profesional' => $usuario->profesional?->nombre,
                    'ultimo_acceso' => $usuario->ultimo_acceso?->toIso8601String(),
                ],
            )->all(),
        ]);
    }

    /**
     * Vincular una cuenta con su ficha de profesional.
     *
     * Sin ficha de profesional, un profesional no puede registrar
     * intervenciones ni bloquear una alergia, aunque su cuenta tenga el rol
     * correcto: la intervencion quedaria sin tratante y eso no se puede
     * sostener. Ver CapacidadesDeUsuario::puedeRegistrarIntervencion.
     *
     * La cuenta tiene que existir: esto no la crea. La crea Authentik.
     */
    public function vincularProfesional(Request $peticion, Usuario $usuario): JsonResponse
    {
        $this->authorize('update', $usuario);

        $datos = $peticion->validate([
            'profesional_id' => ['nullable', 'uuid'],
        ]);

        $profesionalId = $datos['profesional_id'] ?? null;

        if ($profesionalId !== null) {
            $existe = Profesional::query()
                ->where('tenant_id', $this->consultorioId())
                ->whereKey($profesionalId)
                ->exists();

            if (! $existe) {
                return response()->json([
                    'mensaje' => 'El profesional no existe en este consultorio.',
                ], 422);
            }
        }

        $usuario->forceFill(['profesional_id' => $profesionalId])->save();

        return response()->json([
            'mensaje' => 'Ficha de profesional actualizada.',
            'id' => $usuario->id,
            'profesional_id' => $usuario->profesional_id,
        ]);
    }

    /**
     * Desactivar una cuenta.
     *
     * En la sesion siguiente deja de operar: `ResolverTenant` corta con 403
     * antes de tocar la base. No se borra la cuenta, porque sus intervenciones
     * quedan firmadas con su nombre.
     */
    public function desactivar(Usuario $usuario): JsonResponse
    {
        $this->authorize('update', $usuario);

        if ((string) $usuario->id === (string) $this->usuario()->id) {
            return response()->json([
                'mensaje' => 'Una cuenta no se desactiva a si misma. Pida que lo haga otro administrador.',
            ], 422);
        }

        $usuario->forceFill(['activo' => false])->save();

        return response()->json([
            'mensaje' => 'Cuenta desactivada. Deja de operar en su sesion siguiente.',
            'id' => $usuario->id,
            'activo' => false,
        ]);
    }
}