<?php

declare(strict_types=1);

namespace App\Services\Fichas;

use App\Enums\AccionOdontograma;
use App\Enums\ResultadoAcceso;
use App\Models\AccesoOdontograma;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bitácora de accesos al historial odontológico.
 *
 * Sin excepciones: TODO intento queda anotado, permitido o denegado.
 *
 * POR QUÉ UNA CONEXIÓN APARTE
 * --------------------------
 * La petición corre dentro de una transacción (ver `ResolverTenant`). Si el
 * acceso es denegado, el controlador lanza una excepción y PostgreSQL
 * REVIERTE esa transacción: una bitácora escrita en la misma conexión se
 * perdería justo en el caso que más importa, que es el intento denegado.
 *
 * Por eso esta clase escribe en la conexión `auditoria`, separada y con su
 * propia transacción. El costo es una conexión más; la alternativa es perder
 * el registro de los intentos denegados, que son justamente los que hay que
 * poder revisar después.
 *
 * SI LA PETICIÓN NO TIENE CONSULTORIO
 * ---------------------------------
 * Una petición sin sesión no tiene con qué escribir: no hay
 * `app.tenant_id` y la RLS lo prohíbe. En ese caso el intento va al registro
 * `accesos_rechazados` (ver `config/logging.php`). Es una defensa
 * secundaria: esos casos también los corta el middleware de autenticación.
 */
class BitacoraOdontograma
{
    /** Conexión dedicada a la bitácora. Ver la nota de clase. */
    public const CONEXION = 'auditoria';

    public function __construct(private readonly ContextoTenant $contexto) {}

    /**
     * Anota un intento de acceso al historial odontológico.
     *
     * @param  Usuario|null  $usuario  null si la petición no tiene sesión.
     * @param  string|null  $pacienteId  id del paciente, si se pudo resolver
     *                                   desde la ruta.
     * @param  int|null  $numeroFdi  pieza involucrada, si la hay.
     */
    public function registrar(
        ?Usuario $usuario,
        ?string $pacienteId,
        AccionOdontograma $accion,
        ResultadoAcceso $resultado,
        Request $peticion,
        ?int $numeroFdi = null,
        ?int $codigoRespuesta = null,
    ): void {
        $consultorioId = $usuario?->tenant_id;

        $campos = [
            'usuario_id' => $usuario?->id,
            'paciente_id' => $pacienteId,
            'numero_fdi' => $numeroFdi,
            'accion' => $accion->value,
            'resultado' => $resultado->value,
            'metodo_http' => $peticion->method(),
            'ruta' => $peticion->path(),
            'nombre_ruta' => $peticion->route()?->getName(),
            'direccion_ip' => $peticion->ip(),
            'agente_usuario' => substr((string) $peticion->userAgent(), 0, 500),
            'codigo_respuesta' => $codigoRespuesta,
            'momento' => now(),
        ];

        if ($consultorioId === null) {
            Log::channel('accesos_rechazados')->warning('Intento de acceso al historial odontologico sin consultorio en la sesion.', [
                'usuario_id' => $usuario?->id,
                'correo' => $usuario?->correo,
                'accion' => $accion->value,
                'resultado' => $resultado->value,
                'ruta' => $peticion->path(),
                'metodo' => $peticion->method(),
                'direccion_ip' => $peticion->ip(),
            ]);

            return;
        }

        try {
            DB::connection(self::CONEXION)->transaction(function () use ($consultorioId, $campos): void {
                DB::connection(self::CONEXION)->select(
                    'SELECT set_config(?, ?, true)',
                    [ContextoTenant::VARIABLE, $consultorioId],
                );

                AccesoOdontograma::on(self::CONEXION)->create(
                    array_merge($campos, ['tenant_id' => $consultorioId]),
                );
            });
        } catch (Throwable $e) {
            // Si la bitácora falla, la petición NO debe continuar: un
            // historial que se puede leer sin dejar rastro es peor que una
            // petición que falla. Se corta el acceso y se avisa al operador.
            Log::channel('accesos_rechazados')->critical('No se pudo escribir la bitacora de accesos. Se corta la peticion.', [
                'consultorio_id' => $consultorioId,
                'usuario_id' => $campos['usuario_id'],
                'accion' => $accion->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}