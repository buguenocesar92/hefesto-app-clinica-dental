<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Consultorio;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el consultorio de la petición para que la Row Level Security sepa a
 * quién pertenece cada fila.
 *
 * Se aplica a todo el grupo `web` (ver bootstrap/app.php), ANTES que el
 * middleware `auth` de la ruta. Por eso no puede usar `$request->user()`
 * todavía: resuelve el usuario por su cuenta con el guard `web`, que ya lee
 * la sesión.
 *
 * Peticiones sin sesión (login, callback, salud) pasan sin transacción. Si
 * esas rutas tocaran una tabla protegida, `public.app_tenant_id()` lanzaría
 * excepción y la petición fallaría: el aislamiento falla cerrado, nunca
 * abierto.
 *
 * El cuerpo de la petición corre dentro de la transacción, y eso es
 * deliberado: es lo que hace que `set_config(..., true)` tenga alcance de
 * transacción y el valor no sobreviva a la conexión reutilizada del worker de
 * colas.
 */
class ResolverTenant
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = auth()->guard('web')->user();

        if (! $usuario instanceof Usuario) {
            return $next($request);
        }

        if ($usuario->tenant_id === null) {
            // Una cuenta sin consultorio no puede operar. Se corta aquí, antes
            // de tocar la base de datos.
            abort(403, 'La cuenta no está asociada a ningún consultorio.');
        }

        if (! $usuario->activo) {
            abort(403, 'La cuenta está desactivada. Consulte al administrador del consultorio.');
        }

        return $this->contexto->ejecutarAislado(
            $usuario->tenant_id,
            static fn () => $next($request),
        );
    }

    /**
     * Consultorios activos, para el selector de login.
     *
     * ATENCIÓN: este método consulta la TABLA `consultorios`, no la vista
     * `vw_consultorios_publicos`. Un comentario anterior afirmaba lo contrario
     * y era falso: la vista expone solo tres columnas y no incluye
     * `nombre_corto`, así que el selector lee la tabla completa.
     *
     * La vista existe (migración 2026_02_10_000001) y sigue SIN USO. Antes
     * del login no hay `app.tenant_id`, y leer la tabla cruda es la excepción
     * controlada del patrón: `consultorios` no lleva RLS por eso. Lo que se
     * expone al selector son cinco columnas de identificación, ninguna de
     * contacto ni de datos de pacientes.
     *
     * Dejar la vista sin usar no es un error: es una limitación conocida,
     * registrada en docs/03-MULTITENANCY-Y-ROLES.md. Cambiar esto exige
     * revisar qué espera la interfaz de `nombre_corto`, y eso no se ha podido
     * comprobar sin una base de datos disponible.
     *
     * @return \Illuminate\Support\Collection<int, Consultorio>
     */
    public function consultoriosParaLogin()
    {
        return Consultorio::query()
            ->select('id', 'slug', 'nombre', 'nombre_corto', 'activo')
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }
}