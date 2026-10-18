<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccionOdontograma;
use App\Enums\ResultadoAcceso;
use App\Models\Usuario;
use App\Services\Fichas\BitacoraOdontograma;
use App\Services\Permisos\CapacidadesDeUsuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anota cada intento de abrir el historial odontológico.
 *
 * Sin excepciones: va permitido o denegado. El motivo es que en odontología
 * un historial puede revelar información que el paciente no quiere que vea un
 * tercero, y para eso hace falta poder decir quién estuvo mirando.
 *
 * POR QUÉ ES UN MIDDLEWARE Y NO UN REGISTRO DENTRO DEL CONTROLADOR
 * --------------------------------------------------------------
 * Porque el registro denegado tiene que existir aunque la acción nunca llegue
 * al controlador. Si el permiso se comprueba dentro del controlador y la
 * excepción corta antes de escribir nada, el intento denegado desaparece, y
 * son justo los intentos denegados los que hay que poder revisar.
 *
 * El paciente y la pieza se sacan de la ruta por nombre de parámetro
 * (`paciente`, `pieza`), sin consultar la base: así la bitácora se escribe
 * aunque la consulta que sigue falle.
 */
class AuditarOdontograma
{
    /** Parámetros de ruta de donde se saca la pieza, en orden de preferencia. */
    private const PARAMETROS_PIEZA = ['pieza', 'numero_fdi'];

    public function __construct(private readonly BitacoraOdontograma $bitacora) {}

    public function handle(Request $request, Closure $next, string $accion): Response
    {
        $tipoAccion = AccionOdontograma::from($accion);

        $usuario = auth()->guard('web')->user();
        $usuario = $usuario instanceof Usuario ? $usuario : null;

        $pacienteId = $this->resolverPaciente($request);
        $numeroFdi = $this->resolverPieza($request);

        $capacidades = $usuario === null
            ? null
            : new CapacidadesDeUsuario($usuario);

        $resultado = $this->evaluar($usuario, $capacidades, $tipoAccion);

        // Denegado: se anota y se corta con 404, no con 403. Un 403 confirmaría
        // que el recurso existe.
        if ($resultado !== ResultadoAcceso::Permitido) {
            $this->bitacora->registrar(
                $usuario,
                $pacienteId,
                $tipoAccion,
                $resultado,
                $request,
                $numeroFdi,
                404,
            );

            abort(404, 'No encontrado.');
        }

        $respuesta = $next($request);

        $this->bitacora->registrar(
            $usuario,
            $pacienteId,
            $tipoAccion,
            ResultadoAcceso::Permitido,
            $request,
            $numeroFdi,
            $respuesta->getStatusCode(),
        );

        return $respuesta;
    }

    /**
     * Decide el resultado del intento sin tocar la base de datos de negocio.
     */
    private function evaluar(
        ?Usuario $usuario,
        ?CapacidadesDeUsuario $capacidades,
        AccionOdontograma $accion,
    ): ResultadoAcceso {
        if ($usuario === null) {
            return ResultadoAcceso::SinSesion;
        }

        if ($capacidades === null || ! $capacidades->admite($accion)) {
            return ResultadoAcceso::Denegado;
        }

        return ResultadoAcceso::Permitido;
    }

    /**
     * Identificador del paciente desde el nombre del parámetro de ruta.
     */
    private function resolverPaciente(Request $request): ?string
    {
        $paciente = $request->route('paciente');

        if (is_string($paciente) && $paciente !== '') {
            return $paciente;
        }

        return null;
    }

    /**
     * Número de pieza desde el nombre del parámetro de ruta, si la ruta lo
     * lleva como texto. Si es un modelo, se toma su columna.
     */
    private function resolverPieza(Request $request): ?int
    {
        foreach (self::PARAMETROS_PIEZA as $parametro) {
            $valor = $request->route($parametro);

            if ($valor === null) {
                continue;
            }

            if (is_int($valor)) {
                return $valor;
            }

            if (is_string($valor) && ctype_digit($valor)) {
                return (int) $valor;
            }

            if (is_object($valor) && isset($valor->numero_fdi)) {
                return (int) $valor->numero_fdi;
            }
        }

        return null;
    }
}