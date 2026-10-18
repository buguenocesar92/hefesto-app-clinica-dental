<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LAS REGLAS DE LA CRONOLOGÍA, EN LA BASE DE DATOS.
 *
 * POR QUÉ ESTO NO ES SOBREDISEÑADO
 * --------------------------------
 * Las reglas del odontograma son tres, y las tres se pueden saltaría desde
 * PHP:
 *
 *   1. Una intervención nunca se sobrescribe ni se borra.
 *   2. Un cambio de estado de una pieza siempre viene acompañado de una
 *      intervención nueva.
 *   3. Una pieza extraída no vuelve a un estado natural.
 *
 * Por eso están acá y no solo en `MaquinaDeEstadosPieza`. Un script de
 * importación de un cuaderno viejo, un comando `db:` de Laravel o un
 * `DB::statement` suelto no pasan por el servicio. Una regla de historia
 * clínica que depende de que todos tengan cuidado no es una regla.
 *
 * LOS DISPARADORES
 * ----------------
 * `cronologia_sin_borrado`
 *     BEFORE UPDATE OR DELETE sobre `intervenciones_pieza`. Rechaza siempre.
 *     Una intervención es un hecho ocurrido; los hechos no se editan.
 *     `TRUNCATE` NO se bloquea a propósito: es lo que usan `RefreshDatabase`
 *     y `migrate:fresh` para limpiar entre pruebas, y no se puede usar para
 *     borrar una intervención puntual. Ver `docs/09-API.md`, sección sobre
 *     anulación de registros: esta versión NO ofrece anular, y la razón está
 *     escrita ahí.
 *
 * `piezas_exigen_cronologia`
 *     BEFORE UPDATE sobre `piezas`, y solo cuando cambia `estado`. Exige que
 *     exista una intervención nueva, ya insertada en la misma transacción,
 *     ligada a esa pieza por `ultima_intervencion_id`. Sin eso, el estado
 *     cambió sin dejar rastro, que es exactamente el problema que el
 *     producto viene a resolver.
 *
 * `piezas_no_se_restaura_tras_extraccion`
 *     BEFORE UPDATE sobre `piezas`. Si la pieza estaba `extraida`, el único
 *     destino permitido es `implante`, `protesis` o `puente`. Volver a
 *     `sano` u `obturado` borraría de la faz del mapa la existencia misma
 *     de la pieza: el profesional vería un molar sano y no sabría que hubo
 *     que sacarlo. Y lo que corresponde después de sacar un molar no es
 *     restaurarlo, es reponerlo, que es un hecho distinto con fecha propia.
 *
 * NOTA SOBRE RLS Y DISPARADORES
 * -----------------------------
 * Los disparadores se ejecutan con los permisos de quien escribe, así que la
 * consulta interna del segundo disparador también pasa por la RLS. Como la
 * intervención se inserta en la misma transacción y con el mismo
 * `tenant_id`, es visible para el propio contexto. No hay una puerta trasera.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->bloquearEdicionDeLaCronologia();
        $this->exigirCronologiaAlCambiarEstado();
        $this->impedirRestaurarTrasExtraccion();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cronologia_sin_borrado ON public.intervenciones_pieza');
        DB::statement('DROP FUNCTION IF EXISTS public.fn_cronologia_sin_borrado()');

        DB::statement('DROP TRIGGER IF EXISTS piezas_exigen_cronologia ON public.piezas');
        DB::statement('DROP FUNCTION IF EXISTS public.fn_piezas_exigen_cronologia()');

        DB::statement('DROP TRIGGER IF EXISTS piezas_no_se_restaura_tras_extraccion ON public.piezas');
        DB::statement('DROP FUNCTION IF EXISTS public.fn_piezas_no_se_restaura_tras_extraccion()');
    }

    /**
     * Regla 1: una intervención no se edita ni se borra.
     */
    private function bloquearEdicionDeLaCronologia(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fn_cronologia_sin_borrado()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = 'restrict_violation',
                    MESSAGE = 'La cronología de una pieza es acumulativa: una intervención no se modifica ni se borra.',
                    HINT = 'Para corregir un registro erroneo hay que registrar una intervención nueva que lo explique. Ver docs/02-MODELO-DE-DATOS.md.';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cronologia_sin_borrado
            BEFORE UPDATE OR DELETE ON public.intervenciones_pieza
            FOR EACH ROW
            EXECUTE FUNCTION public.fn_cronologia_sin_borrado();
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON FUNCTION public.fn_cronologia_sin_borrado() IS
                'Bloquea UPDATE y DELETE sobre intervenciones_pieza: la cronologia es acumulativa.';
        SQL);
    }

    /**
     * Regla 2: cambiar el estado exige una intervención nueva.
     */
    private function exigirCronologiaAlCambiarEstado(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fn_piezas_exigen_cronologia()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                hay_cronologia boolean;
            BEGIN
                -- Solo interesa el cambio de estado. Editar las notas de una
                -- pieza sin tocar su estado es una operación normal y no
                -- necesita intervención nueva.
                IF NEW.estado IS NOT DISTINCT FROM OLD.estado THEN
                    RETURN NEW;
                END IF;

                SELECT EXISTS (
                    SELECT 1
                    FROM public.intervenciones_pieza i
                    WHERE i.id = NEW.ultima_intervencion_id
                      AND i.pieza_id = OLD.id
                      AND i.numero_fdi = OLD.numero_fdi
                ) INTO hay_cronologia;

                IF NOT hay_cronologia THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'restrict_violation',
                        MESSAGE = 'El estado de una pieza no se cambia sin registrar la intervención que lo causa.',
                        HINT = 'Use App\Services\Odontograma\ServicioOdontograma::registrar(), que inserta la intervención y actualiza el estado en la misma transaccion.';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER piezas_exigen_cronologia
            BEFORE UPDATE ON public.piezas
            FOR EACH ROW
            EXECUTE FUNCTION public.fn_piezas_exigen_cronologia();
        SQL);
    }

    /**
     * Regla 3: de `extraida` solo se sale hacia un reemplazo.
     */
    private function impedirRestaurarTrasExtraccion(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fn_piezas_no_se_restaura_tras_extraccion()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.estado = 'extraida'
                   AND NEW.estado IS DISTINCT FROM OLD.estado
                   AND NEW.estado NOT IN ('implante', 'protesis', 'puente') THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'restrict_violation',
                        MESSAGE = 'Una pieza extraida no vuelve a un estado natural. Desde extraida solo se puede pasar a implante, protesis o puente.',
                        HINT = 'La extraccion es un hecho y queda en la cronologia. Registrar el implante, la protesis o el puente es un hecho nuevo, con fecha propia.';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER piezas_no_se_restaura_tras_extraccion
            BEFORE UPDATE ON public.piezas
            FOR EACH ROW
            EXECUTE FUNCTION public.fn_piezas_no_se_restaura_tras_extraccion();
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON FUNCTION public.fn_piezas_no_se_restaura_tras_extraccion() IS
                'Impide que una pieza extraida vuelva a sano, obturado, corona u otro estado natural.';
        SQL);
    }
};