<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Consultorio;
use App\Models\Usuario;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contexto de consultorio de la petición.
 *
 * PATRÓN REUTILIZADO
 * ------------------
 * Esta clase es la misma del portal de autoservicio y de la clínica general,
 * a propósito: el aislamiento entre clientes se decidió una vez en Hefesto y
 * todas las aplicaciones lo repiten. La justificación completa está en
 * `apps/portal-autoservicio/docs/03-MULTITENANCY.md`.
 *
 * Resumen de por qué `set_config(..., true)` y no `SET`:
 *
 * El alcance de TRANSACCIÓN hace que PostgreSQL descarte la variable al
 * confirmar o revertir. Con un `SET` de sesión, el valor sobrevive a la
 * petición, y el worker de colas —que reutiliza la misma conexión para
 * trabajos de consultorios distintos— leería los datos de otro consultorio.
 * Es exactamente el fallo que este diseño evita, y por eso `establecer()`
 * EXIGE estar dentro de una transacción y lanza excepción si no lo está.
 */
class ContextoTenant
{
    /** Variable de sesión de PostgreSQL que lee la política de RLS. */
    public const VARIABLE = 'app.tenant_id';

    /**
     * Fija el consultorio de la petición en la conexión actual.
     *
     * @throws RuntimeException si no hay una transacción abierta.
     */
    public function establecer(string $consultorioId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'No se puede fijar el contexto de consultorio fuera de una transaccion. '
                . 'Use App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
            );
        }

        DB::select('SELECT set_config(?, ?, true)', [self::VARIABLE, $consultorioId]);
    }

    /**
     * Ejecuta una operación dentro de una transacción con el consultorio fijo.
     * Al salir, PostgreSQL descarta el valor de la variable.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    public function ejecutarAislado(string $consultorioId, Closure $operacion): mixed
    {
        return DB::transaction(function () use ($consultorioId, $operacion) {
            $this->establecer($consultorioId);

            return $operacion();
        });
    }

    /**
     * Lee la variable de sesión sin pasar por `public.app_tenant_id()`, que
     * lanza excepción cuando no hay contexto. Sirve para diagnóstico: si
     * devuelve null, ninguna política de RLS va a devolver filas.
     */
    public function consultorioIdEnConexion(): ?string
    {
        $fila = DB::selectOne('SELECT current_setting(?, true) AS valor', [self::VARIABLE]);

        $valor = $fila->valor ?? null;

        return $valor === null || $valor === '' ? null : $valor;
    }

    /** Identificador del consultorio autenticado, si lo hay. */
    public function consultorioIdSolicitado(): ?string
    {
        $usuario = auth()->user();

        return $usuario instanceof Usuario ? $usuario->tenant_id : null;
    }

    /**
     * Resuelve el consultorio desde un modelo, para los casos en que se
     * escribe con el mismo `usuario` de la sesión.
     */
    public function idDe(Usuario|Consultorio $contexto): string
    {
        return $contexto instanceof Usuario ? (string) $contexto->tenant_id : (string) $contexto->id;
    }
}