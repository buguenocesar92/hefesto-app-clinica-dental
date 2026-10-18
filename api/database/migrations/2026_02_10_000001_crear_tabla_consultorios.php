<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consultorios: el tenant.
 *
 * ESTA TABLA NO LLEVA RLS, A PROPOSITO
 * ------------------------------------
 * Se consulta durante el login, cuando todavía no hay sesión y por lo tanto
 * no hay `app.tenant_id` que poner. Es la excepción consciente del patrón,
 * y está compensada por dos cosas:
 *
 *  1. La vista `vw_consultorios_publicos` (migración de más abajo), que
 *     expone tres columnas y solo los consultorios activos. Antes del login
 *     la aplicación no lee esta tabla cruda.
 *  2. La verificación de despliegue `dental:verificar-aislamiento`, que
 *     comprueba que el rol de la base no sea superusuario ni tenga BYPASSRLS.
 *
 * El riesgo residual —que un `slug` se pueda enumerar antes de autenticarse—
 * está dicho con todas las letras en `docs/03-MULTITENANCY-Y-ROLES.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultorios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('slug', 60)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('nombre_corto', 60);
            $tabla->string('direccion', 240)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();
        });

        // Solo lo que se puede mostrar en un selector de login: nombre de la
        // marca, no razón social, no RUT, no correos, no teléfonos.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_consultorios_publicos AS
            SELECT
                slug,
                nombre AS nombre_mostrado,
                activo
            FROM public.consultorios
            WHERE activo;
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON VIEW public.vw_consultorios_publicos IS
                'Unica fuente de datos de consultorios antes del login. Expone slug, nombre mostrado y activo, nada mas.';
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS public.vw_consultorios_publicos');
        Schema::dropIfExists('consultorios');
    }
};