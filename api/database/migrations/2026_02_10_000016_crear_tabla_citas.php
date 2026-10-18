<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Citas de la agenda clínica.
 *
 * LA DURACIÓN VIENE DEL PROCEDIMIENTO
 * -----------------------------------
 * `fecha_fin` NO es `fecha_inicio` más una constante. Sale de
 * `procedimientos.duracion_minutos`, que es la duración que declara el
 * consultorio para ese procedimiento. Es la respuesta directa a "no todos
 * ocupan el mismo tiempo, y agendar todo en bloques iguales es lo que hace
 * que se atrase la agenda".
 *
 * La duración se copia a `fecha_fin` y también se guarda en
 * `duracion_minutos`, para que un cambio posterior en el catálogo no
 * redibuje el pasado: la cita que fue de 60 minutos sigue siendo, en el
 * registro, una cita de 60 minutos.
 *
 * EL ÍNDICE EXCLUSIVO ES LO QUE IMPIDE EL DOBLE AGENDADO
 * -----------------------------------------------------
 * La restricción `EXCLUDE USING gist` con `&&` sobre el rango de tiempo, y
 * el filtro `WHERE (estado IN (...))`, obliga a que dos citas que ocupan
 * agenda del mismo profesional no se solapen. Vive en la base: no depende de
 * que el controlador haya comprobado bien.
 *
 * Para eso hace falta la extensión `btree_gist`, que se activa más abajo.
 * Es una extensión estándar de PostgreSQL, incluida en la imagen oficial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('paciente_id');
            $tabla->uuid('profesional_id');
            $tabla->uuid('procedimiento_id');
            $tabla->uuid('plan_tratamiento_id')->nullable();
            $tabla->uuid('etapa_plan_id')->nullable();
            $tabla->timestampTz('fecha_inicio');
            $tabla->timestampTz('fecha_fin');
            $tabla->unsignedSmallInteger('duracion_minutos');
            $tabla->string('estado', 16)->default('agendada');
            $tabla->string('silla', 24)->nullable();
            $tabla->string('motivo', 240)->nullable();
            $tabla->text('notas')->nullable();
            $tabla->uuid('desbloqueo_alergia_id')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->restrictOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->restrictOnDelete();
            $tabla->foreign('procedimiento_id')->references('id')->on('procedimientos')->restrictOnDelete();
            $tabla->foreign('plan_tratamiento_id')->references('id')->on('planes_tratamiento')->nullOnDelete();
            $tabla->foreign('etapa_plan_id')->references('id')->on('etapas_plan')->nullOnDelete();
            $tabla->foreign('desbloqueo_alergia_id')->references('id')->on('desbloqueos_alergia')->nullOnDelete();

            $tabla->index(['tenant_id', 'profesional_id', 'fecha_inicio']);
            $tabla->index(['tenant_id', 'paciente_id', 'fecha_inicio']);
        });

        // `btree_gist` hace que un `uuid` pueda combinarse con el operador de
        // rango del índice GiST. Sin esto, la exclusión de solapamiento no se
        // puede escribir.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE citas
            ADD CONSTRAINT citas_sin_solapamiento
            EXCLUDE USING gist (
                tenant_id WITH =,
                profesional_id WITH =,
                tstzrange(fecha_inicio, fecha_fin, '[)') WITH &&
            )
            WHERE (estado IN ('agendada', 'confirmada', 'atendida', 'bloqueada'));
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON CONSTRAINT citas_sin_solapamiento ON public.citas IS
                'Impide que dos citas del mismo profesional se solapen en el tiempo. Las citas anuladas o con ausencia no ocupan agenda y quedan fuera.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};