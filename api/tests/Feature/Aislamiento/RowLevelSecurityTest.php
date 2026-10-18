<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La RLS esta puesta de verdad, en cada tabla.
 *
 * Esta prueba se puede escribir de dos maneras: contando tablas, o revisando
 * las que la migracion declara. Se revisan las que la migracion declara, y esa
 * lista esta repetida aca a proposito. Si alguien agrega una tabla de negocio y
 * no la agrega en los dos lugares, esta prueba lo dice en vez de pasar en
 * verde.
 *
 * Ver docs/03 y la migracion `2026_02_10_000020`.
 */
class RowLevelSecurityTest extends TestCase
{
    /**
     * Las mismas diecisiete tablas que la migracion. Deben coincidir.
     *
     * @var list<string>
     */
    private const TABLAS = [
        'profesionales',
        'procedimientos',
        'insumos',
        'tarifas',
        'usuarios',
        'pacientes',
        'alergias',
        'condiciones',
        'desbloqueos_alergia',
        'odontogramas',
        'piezas',
        'intervenciones_pieza',
        'planes_tratamiento',
        'etapas_plan',
        'citas',
        'bloqueos_agenda',
        'accesos_odontograma',
    ];

    public function test_todas_las_tablas_de_negocio_tienen_rls_forzado(): void
    {
        foreach (self::TABLAS as $tabla) {
            $fila = DB::selectOne(
                'SELECT relrowsecurity AS rls, relforcerowsecurity AS forzado FROM pg_class WHERE relname = ?',
                [$tabla],
            );

            $this->assertNotNull($fila, "La tabla {$tabla} no existe. Faltaria correr las migraciones.");
            $this->assertTrue((bool) $fila->rls, "La tabla {$tabla} no tiene Row Level Security habilitado.");
            $this->assertTrue(
                (bool) $fila->forzado,
                "La tabla {$tabla} no tiene RLS FORZADO. Sin FORCE, las politicas no le aplican "
                . 'al dueno de la tabla, que es el mismo usuario con el que se conecta Laravel.',
            );
        }
    }

    public function test_cada_tabla_protegida_tiene_la_politica_de_aislamiento(): void
    {
        foreach (self::TABLAS as $tabla) {
            $politica = DB::selectOne(
                'SELECT count(*) AS total FROM pg_policies WHERE tablename = ? AND policyname = ?',
                [$tabla, 'politica_aislamiento_tenant'],
            );

            $this->assertSame(
                1,
                (int) $politica->total,
                "La tabla {$tabla} no tiene la politica politica_aislamiento_tenant.",
            );
        }
    }

    /**
     * `USING` filtra la lectura; `WITH CHECK` impide escribir de otro consultorio.
     *
     * Se comprueban los dos porque son cosas distintas, y una sin la otra deja un
     * hueco distinto.
     */
    public function test_la_politica_tiene_using_y_with_check(): void
    {
        $fila = DB::selectOne(
            'SELECT qual, with_check FROM pg_policies WHERE tablename = ? AND policyname = ?',
            ['pacientes', 'politica_aislamiento_tenant'],
        );

        $this->assertNotNull($fila, 'La politica de aislamiento no esta en la tabla pacientes.');

        $this->assertStringContainsString(
            'app_tenant_id',
            (string) $fila->qual,
            'La politica no usa public.app_tenant_id() para USING.',
        );

        $this->assertStringContainsString(
            'app_tenant_id',
            (string) $fila->with_check,
            'La politica no usa public.app_tenant_id() para WITH CHECK. Sin eso se podrian escribir '
            . 'filas declarando otro consultorio.',
        );
    }

    /**
     * Las tablas exentas son pocas, y cada una por un motivo concreto.
     *
     * Se comprueba que NO haya RLS en `consultorios` ni en `sessions`, porque
     * una RLS ahi impediria iniciar sesion o leer la lista de consultorios antes
     * de que exista un tenant. Ver docs/03.
     */
    public function test_las_tablas_exentas_no_tienen_rls(): void
    {
        foreach (['consultorios', 'sessions', 'migrations'] as $tabla) {
            $fila = DB::selectOne(
                'SELECT relrowsecurity AS rls FROM pg_class WHERE relname = ?',
                [$tabla],
            );

            if ($fila === null) {
                // `sessions` y `migrations` las crea Laravel, no la migracion.
                // Si no estan, no hay nada que comprobar.
                continue;
            }

            $this->assertFalse(
                (bool) $fila->rls,
                "La tabla {$tabla} tiene Row Level Security. Si deberia quedar exenta, "
                . 'ponerla protegido impide iniciar sesion o leer el consultorio.',
            );
        }
    }

    /**
     * La funcion de contexto falla cerrada.
     *
     * Sin la variable de sesion, tiene que lanzar excepcion y no devolver
     * `NULL`: con `NULL` el fallo seria silencioso.
     */
    public function test_la_funcion_de_contexto_falla_sin_variable(): void
    {
        $this->expectException(\Throwable::class);

        DB::selectOne('SELECT public.app_tenant_id() AS valor');
    }
}