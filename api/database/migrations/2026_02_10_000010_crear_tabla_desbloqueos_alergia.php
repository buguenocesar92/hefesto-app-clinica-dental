<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desbloqueos de alergia y de condición.
 *
 * POR QUÉ EXISTE UNA TABLA DE DESBLOQUEO
 * --------------------------------------
 * La regla es que una alergia activa bloquea la atención. Si no hubiera forma
 * de pasar, el primer paciente con alergia a los penicilínicos quedaría sin
 * poder atenderse nunca, y un sistema que obliga a hacer trampa no es un
 * sistema seguro: es uno que enseña a saltarse la regla.
 *
 * Un desbloqueo no es una bandera, es un **acto clínico registrado**: quién
 * lo hizo, sobre qué alergia, para qué procedimiento, con qué motivo y
 * cuándo. Sin ese registro, el desbloqueo no existe. Si el profesional se
 * equivoca al desbloquear, la bitácora dice quién y cuándo.
 *
 * `vigente_hasta` acota el desbloqueo: un desbloqueo para una profilaxis no
 * habilita una endodoncia la semana siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desbloqueos_alergia', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('paciente_id');
            $tabla->uuid('alergia_id')->nullable();
            $tabla->uuid('condicion_id')->nullable();
            $tabla->uuid('profesional_id');
            $tabla->uuid('procedimiento_id')->nullable();
            $tabla->string('motivo', 400);
            $tabla->timestampTz('concedido');
            $tabla->date('vigente_hasta')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();
            $tabla->foreign('alergia_id')->references('id')->on('alergias')->cascadeOnDelete();
            $tabla->foreign('condicion_id')->references('id')->on('condiciones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->restrictOnDelete();
            $tabla->foreign('procedimiento_id')->references('id')->on('procedimientos')->nullOnDelete();

            $tabla->index(['tenant_id', 'paciente_id', 'vigente_hasta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desbloqueos_alergia');
    }
};