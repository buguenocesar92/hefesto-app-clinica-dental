<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Consultorio;
use App\Models\Usuario;
use App\Services\Auth\ProveedorOidcAuthentik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Autenticación contra Authentik (OIDC).
 *
 * El login es de redirección: el navegador va a Authentik, vuelve con un
 * código, y Laravel cambia ese código por un id_token firmado. No hay
 * contraseña manejada por esta aplicación, y no hay ningún secreto en el
 * código del navegador.
 *
 * CÓMO SE RESUELVE EL CONSULTORIO
 * -------------------------------
 * Un usuario de Authentik puede tener acceso a más de un consultorio. Antes
 * de crear la sesión se pregunta cuál es. Se lee de los grupos del token: el
 * grupo del consultorio tiene el prefijo `consultorio-`, y el resto del nombre
 * es su identificador. Sin esa correspondencia, la persona entra pero la
 * petición se corta en `ResolverTenant` con un 403 y un mensaje claro.
 *
 * Ver `docs/02-AUTENTICACION.md` y `docs/05-CONFIGURACION.md`.
 */
class AuthController extends Controller
{
    /** Prefijo del grupo de Authentik que declara el consultorio. */
    private const PREFIJO_GRUPO_CONSULTORIO = 'consultorio-';

    public function __construct(private readonly ProveedorOidcAuthentik $proveedor) {}

    /**
     * Redirige al proveedor de identidad.
     *
     * `$consultorio` es opcional: sin él, Authentik muestra su propia lista de
     * fuentes. Se acepta igual para las instalaciones con una sola fuente.
     */
    public function login(Request $request, string $consultorio = ''): RedirectResponse
    {
        return $this->proveedor->redirigir($request, $consultorio);
    }

    /**
     * Callback del proveedor: cambia el código por tokens y abre la sesión.
     */
    public function callback(Request $request): RedirectResponse
    {
        $datos = $this->proveedor->validarCallback($request);

        $usuario = $this->proveedor->usuarioDesdeClaims($datos);

        if ($usuario === null) {
            return redirect('/login?error=no_registrado')
                ->with('error', 'La cuenta no está registrada en ningún consultorio.');
        }

        Auth::login($usuario, true);

        $request->session()->regenerate();

        return redirect('/');
    }

    /**
     * Cierra la sesión y vuelve al login.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * Datos del usuario de la sesión, para la interfaz.
     *
     * Devuelve también las capacidades, para que el navegador no tenga que
     * adivinar qué botones mostrar. Ocultar un botón NO es una medida de
     * seguridad: la comprobación real está en el servidor. Ver docs/03.
     */
    public function usuario(): JsonResponse
    {
        $usuario = $this->usuario();
        $capacidades = $this->capacidades();

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            'rol' => $usuario->rol->value,
            'rol_etiqueta' => $usuario->rol->etiqueta(),
            'consultorio' => $this->datosDelConsultorio($usuario),
            'capacidades' => [
                'ver_estado_actual' => $capacidades->puedeVerEstadoActual(),
                'ver_cronologia' => $capacidades->puedeVerCronologia(),
                'registrar_intervencion' => $capacidades->puedeRegistrarIntervencion(),
                'ver_planes' => $capacidades->puedeVerPlanes(),
                'editar_planes' => $capacidades->puedeEditarPlanes(),
                'desbloquear_alergias' => $capacidades->puedeDesbloquearAlergias(),
                'administra' => $capacidades->administraConsultorio(),
                'ver_auditoria' => $capacidades->puedeVerAuditoria(),
            ],
        ]);
    }

    /**
     * Consultorio de la sesión.
     *
     * `consultorios` no lleva Row Level Security (se consulta en el login,
     * antes de que exista `app.tenant_id`), así que esta lectura se hace por
     * identificador y no por alcance.
     */
    private function datosDelConsultorio(Usuario $usuario): array
    {
        $consultorio = Consultorio::query()->find($usuario->tenant_id);

        return [
            'id' => $usuario->tenant_id,
            'nombre' => $consultorio?->nombre ?? 'Consultorio',
            'nombre_corto' => $consultorio?->nombre_corto ?? '',
        ];
    }
}