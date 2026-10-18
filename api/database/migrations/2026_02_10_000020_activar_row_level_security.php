<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AISLAMIENTO ENTRE CONSULTORIOS: Row Level Security de PostgreSQL.
 *
 * PATRÓN REUTILIZADO, NO INVENTADO
 * --------------------------------
 * Este es el mismo patrón documentado en
 * `apps/portal-autoservicio/docs/03-MULTITENANCY.md` y repetido en
 * `apps/clinica-salud`. Columna `tenant_id` más políticas de RLS, con
 * `WITH CHECK` y `FORCE ROW LEVEL SECURITY`. No hay un cuarto enfoque.
 *
 * El análisis de por qué NO esquema por consultorio está en el documento del
 * portal. Aquí se repite solo lo esencial: con esquema por cliente, las
 * migraciones se multiplican, el pool de conexiones se dimensiona por
 * cliente, las herramientas de administración se complican, y sobre todo el
 * aislamiento queda en manos de un parámetro de sesión que se puede quedar
 * mal puesto sin dar ningún aviso. Con RLS, un error en PHP no filtra datos:
 * es un error.
 *
 * CUATRO COSAS QUE ESTA MIGRACIÓN HACE
 * ------------------------------------
 * 1. `public.app_tenant_id()` Lanza excepción si no hay contexto. Podría
 *    devolver NULL y filtrar todo en silencio; se eligió que falle ruidoso,
 *    para que el log diga exactamente qué operación se saltó el middleware.
 * 2. Política `FOR ALL` con `USING` (lectura) y `WITH CHECK` (escritura).
 *    Sin `WITH CHECK` se podría insertar una fila declarando otro
 *    consultorio: la lectura la ocultaría y nadie la vería nunca.
 * 3. `FORCE ROW LEVEL SECURITY` en cada tabla, para que la política aplique
 *    también al dueño de la tabla. En desarrollo el rol de la aplicación
 *    suele ser el mismo que creó las tablas, o sea el dueño: sin FORCE el
 *    aislamiento sería decorativo justo donde se prueba.
 * 4. `set_config(..., true)`, con alcance de TRANSACCIÓN. Ver
 *    `App\Services\Tenancy\ContextoTenant`: el worker de colas reutiliza la
 *    conexión y con un `SET` de sesión arrastraría el contexto de otro
 *    consultorio.
 *
 * `consultorios` NO está en la lista, a propósito: se consulta en el login,
 * antes de que exista sesión. Se compensa con la vista
 * `vw_consultorios_publicos` y con el comando
 * `php artisan dental:verificar-aislamiento`.
 *
 * REQUISITO DE DESPLIEGUE: el rol de la aplicación no puede ser
 * superusuario ni tener BYPASSRLS. Con superusuario, PostgreSQL ignora las
 * políticas por completo.
 */
return new class extends Migration
{
    /**
     * Tablas de negocio, todas con `tenant_id`.
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

    public function up(): void
    {
        $this->crearFuncionDeContexto();

        foreach (self::TABLAS as $tabla) {
            $this->exigirColumnaTenant($tabla);
            $this->proteger($tabla);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            DB::statement('ALTER TABLE ' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE ' . $tabla . ' DISABLE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS politica_aislamiento_tenant ON ' . $tabla);
        }

        DB::statement('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * Función que lee el contexto de consultorio de la petición.
     *
     * `current_setting(..., true)` devuelve NULL si la variable no existe, en
     * vez de fallar, que es justo el caso que hay que detectar.
     */
    private function crearFuncionDeContexto(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION
                        'Falta el contexto de consultorio (app.tenant_id). '
                        . 'Usa App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN valor::uuid;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            COMMENT ON FUNCTION public.app_tenant_id() IS
                'Devuelve el consultorio de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL);
    }

    /**
     * Falla ruidosamente si alguna tabla de la lista no tiene `tenant_id`.
     *
     * Sin esta comprobación, un error de tipeo en la lista dejaría una tabla
     * de datos de salud sin protección y la migración pasaría igual.
     */
    private function exigirColumnaTenant(string $tabla): void
    {
        $fila = DB::selectOne(
            'SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = \'public\' AND table_name = ? AND column_name = \'tenant_id\'
             ) AS tiene_columna',
            [$tabla],
        );

        if ($fila === null || ! $fila->tiene_columna) {
            throw new RuntimeException(
                'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                . 'Revise la migración antes de continuar.'
            );
        }
    }

    private function proteger(string $tabla): void
    {
        DB::statement('ALTER TABLE ' . $tabla . ' ENABLE ROW LEVEL SECURITY');

        // Sin esto, las políticas no aplican al dueño de la tabla.
        DB::statement('ALTER TABLE ' . $tabla . ' FORCE ROW LEVEL SECURITY');

        DB::statement(<<<SQL
            CREATE POLICY politica_aislamiento_tenant ON {$tabla}
                FOR ALL
                TO PUBLIC
                USING (tenant_id = public.app_tenant_id())
                WITH CHECK (tenant_id = public.app_tenant_id())
        SQL);
    }
};