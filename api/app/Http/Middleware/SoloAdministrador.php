<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta las rutas de administración del consultorio.
 *
 * Lo hace antes de llegar al controlador, y por eso devuelve 403: acá no se
 * está confirmando la existencia de un recurso, se está confirmando que la
 * persona no tiene permiso para esta sección. La respuesta 404 se reserva
 * para los recursos, en `bootstrap/app.php`.
 */
class SoloAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = auth()->guard('web')->user();

        if (! $usuario instanceof Usuario || ! $usuario->esAdministrador()) {
            abort(403, 'Esta sección es solo para la administración del consultorio.');
        }

        return $next($request);
    }
}