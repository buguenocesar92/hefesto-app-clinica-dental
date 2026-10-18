<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\RolDental;
use App\Models\Consultorio;
use App\Models\Paciente;
use App\Models\Profesional;
use App\Models\Procedimiento;
use App\Models\Usuario;
use App\Services\Odontograma\ServicioOdontograma;
use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de las pruebas de ClinicaDental.
 *
 * POR QUE TODAS LAS PRUEBAS NECESITAN `dentroDe`
 * ----------------------------------------------
 * Las tablas de negocio tienen Row Level Security FORZADO, para que las
 * politicas apliquen tambien al dueno de la tabla (que es el usuario con el
 * que se conecta Laravel). Consecuencia: si una prueba crea filas sin declarar
 * el consultorio al que pertenecen, la base las rechaza.
 *
 * Eso no es un estorbo para probar, es parte de lo que se prueba. `dentroDe` es
 * el mismo camino que usa la peticion real: una transaccion con el contexto
 * fijado y alcance de transaccion.
 *
 * POSTGRESQL Y NO SQLITE
 * ----------------------
 * En SQLite estas pruebas no comprobarian nada, porque no hay RLS: pasarian en
 * verde sobre un sistema sin aislamiento. Por eso `phpunit.xml` fija
 * `DB_CONNECTION=pgsql` y una base de pruebas PostgreSQL. Ver docs/10.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Ejecuta una operacion dentro del contexto de un consultorio.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    protected function dentroDe(Usuario|Consultorio $contexto, Closure $operacion): mixed
    {
        $consultorioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return app(ContextoTenant::class)->ejecutarAislado($consultorioId, $operacion);
    }

    /**
     * Crea un consultorio.
     *
     * `consultorios` no tiene RLS a proposito (se consulta antes de que exista
     * `app.tenant_id`), asi que se puede crear sin contexto.
     */
    protected function crearConsultorio(string $slug = 'consultorio-de-prueba'): Consultorio
    {
        return Consultorio::query()->create([
            'slug' => $slug,
            'nombre' => 'Consultorio de prueba',
            'nombre_corto' => 'Prueba',
            'activo' => true,
        ]);
    }

    /**
     * Crea una cuenta. Sin ficha de profesional salvo que se pida.
     */
    protected function crearUsuario(
        Usuario|Consultorio $contexto,
        RolDental $rol = RolDental::Recepcion,
        ?string $profesionalId = null,
    ): Usuario {
        $consultorioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Usuario => Usuario::query()->create([
            'tenant_id' => $consultorioId,
            'nombre' => 'Persona de prueba',
            'correo' => 'persona' . substr(md5($consultorioId . $rol->value), 0, 8) . '@ejemplo.cl',
            'rol' => $rol->value,
            'profesional_id' => $profesionalId,
            'activo' => true,
        ]));
    }

    /**
     * Crea un profesional y su cuenta con rol `profesional`.
     *
     * La cuenta va CON ficha de profesional, porque sin ella no puede registrar
     * intervenciones ni desbloquear alergias: una intervencion sin tratante es un
     * hecho que no se puede sostener. Ver CapacidadesDeUsuario.
     *
     * @return array{0: Usuario, 1: Profesional}
     */
    protected function crearProfesional(Usuario|Consultorio $contexto, string $nombre = 'Profesional de prueba'): array
    {
        $consultorioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, function () use ($consultorioId, $nombre): array {
            $profesional = Profesional::query()->create([
                'tenant_id' => $consultorioId,
                'nombre' => $nombre,
                'especialidad' => 'Odontologia general',
            ]);

            $usuario = Usuario::query()->create([
                'tenant_id' => $consultorioId,
                'nombre' => $nombre,
                'correo' => 'profesional' . substr(md5($consultorioId . $nombre), 0, 8) . '@ejemplo.cl',
                'rol' => RolDental::Profesional->value,
                'profesional_id' => $profesional->id,
                'activo' => true,
            ]);

            return [$usuario, $profesional];
        });
    }

    protected function crearPaciente(Usuario|Consultorio $contexto): Paciente
    {
        $consultorioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Paciente => Paciente::query()->create([
            'tenant_id' => $consultorioId,
            'nombres' => 'Paciente',
            'apellidos' => 'De Prueba',
            'fecha_nacimiento' => '1985-04-12',
            'sexo' => 'no_informado',
            'activo' => true,
        ]));
    }

    protected function crearProcedimiento(
        Usuario|Consultorio $contexto,
        string $nombre = 'Obturacion',
        int $duracion = 45,
        string $codigo = 'OBT-001',
        bool $esQuirurgico = false,
    ): Procedimiento {
        $consultorioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Procedimiento => Procedimiento::query()->create([
            'tenant_id' => $consultorioId,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'categoria' => 'general',
            'duracion_minutos' => $duracion,
            'es_quirurgico' => $esQuirurgico,
            'requiere_anamnesis' => true,
            'activo' => true,
        ]));
    }

    /**
     * Odontograma del paciente con sus 32 piezas, por el servicio y no a mano.
     */
    protected function crearOdontograma(Usuario $usuario, Paciente $paciente): object
    {
        return $this->dentroDe($usuario, fn () => app(ServicioOdontograma::class)->crearOdontograma($paciente));
    }
}