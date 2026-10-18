<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos de agenda: vacaciones, reuniones y otra indisponibilidad.
 *
 * Se guardan en la misma tabla conceptual que las citas pero aparte, porque
 * su naturaleza es distinta: un bloqueo no tiene paciente ni procedimiento, y
 * no genera un hecho clínico. Lo que tiene en común es que ocupa el tiempo
 * del profesional, y por eso comparte la restricción de solapamiento (que
 * vive en `citas` y se aplica solo a esa tabla).
 *
 * Para que un bloqueo y una cita no puedan pisarse, la reserva se hace en el
 * servicio `App\Services\Agenda\VerificadorDeDisponibilidad`, que consulta
 * ambos. La exclusión dura está en `citas`; entre tablas distintas PostgreSQL
 * no la puede hacer con una restricción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloqueos_agenda', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('profesional_id');
            $tabla->timestampTz('fecha_inicio');
            $tabla->timestampTz('fecha_fin');
            $tabla->string('motivo', 240);
            $tabla->boolean('todo_el_dia')->default(false);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'profesional_id', 'fecha_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bloqueos_agenda');
    }
};