<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base de los controladores de la API.
 *
 * `capacidades()` es el atajo que usan los controladores para no tener que
 * repetir la construcción del servicio de permisos en cada método.
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Usuario de la sesión, o 401 si no hay ninguno.
     */
    protected function usuario(): Usuario
    {
        $usuario = auth()->user();

        if (! $usuario instanceof Usuario) {
            abort(401, 'La sesión no es válida o venció.');
        }

        return $usuario;
    }

    /**
     * Capacidades del usuario de la sesión.
     */
    protected function capacidades(): CapacidadesDeUsuario
    {
        return new CapacidadesDeUsuario($this->usuario());
    }

    /**
     * Identificador del consultorio de la sesión.
     *
     * Lo escribe el middleware `ResolverTenant` en todas las filas nuevas. Va
     * explícito y no se deduce del modelo relacionado: es el mismo dato que
     * está en `app.tenant_id`, y que coincidan es justamente lo que hay que
     * comprobar.
     */
    protected function consultorioId(): string
    {
        return (string) $this->usuario()->tenant_id;
    }
}