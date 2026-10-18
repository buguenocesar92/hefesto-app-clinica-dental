<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alergias del paciente.
 *
 * ESTA TABLA BLOQUEA LA ATENCIÓN, NO AVISA
 * -----------------------------------------
 * `activa` es lo que decide si la alergia bloquea el agendamiento. Ver
 * `App\Services\Alergias\RevisorDeObstaclos`: toda alergia activa bloquea,
 * sin excepción, y la única forma de pasar es registrar un desbloqueo
 * explícito con su profesional y su motivo, en `desbloqueos_alergia`.
 *
 * El motivo de que sea así está en `docs/07-SEGURIDAD-Y-LEY-19628.md`: una
 * alergia mostrada como nota al pie de una ficha es una alergia que tarde o
 * temprano alguien no ve, y en odontología una reacción anafiláctica a un
 * anestésico o a un látex es una muerte.
 *
 * NUNCA SE BORRA UNA ALERGIA
 * --------------------------
 * Una alergia se cierra con `activa = false` y se anota por qué y cuándo. Si
 * se borrara la fila, la cronología del paciente perdería el hecho de que
 * tuvo esa alergia, y con ella el registro de quién la evaluó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alergias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('paciente_id');
            $tabla->uuid('profesional_id')->nullable();
            $tabla->string('sustancia', 120);
            $tabla->string('reaccion', 240);
            $tabla->string('severidad', 16)->default('leve');
            $tabla->boolean('activa')->default(true);
            $tabla->date('fecha_registro');
            $tabla->date('fecha_cierre')->nullable();
            $tabla->string('motivo_cierre', 240)->nullable();
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->nullOnDelete();

            // Índice de apoyo para el caso más frecuente: "¿qué bloquea a este
            // paciente ahora mismo?".
            $tabla->index(['tenant_id', 'paciente_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alergias');
    }
};