<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapas de un plan de tratamiento.
 *
 * Una etapa es una unidad de trabajo con su propio estado y su propia fecha.
 * Puede o no tocar piezas: si toca, `pieza_id` apunta a la pieza, y la
 * intervención que la ejecute debería apuntar a esta etapa.
 *
 * `pieza_id` usa `restrictOnDelete` a propósito: no se puede borrar una pieza
 * que tiene etapas de plan. Una pieza con plan es un hecho; se archiva el
 * plan, no se borra la pieza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapas_plan', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('plan_tratamiento_id');
            $tabla->uuid('pieza_id')->nullable();
            $tabla->smallInteger('numero_fdi')->nullable();
            $tabla->unsignedSmallInteger('orden');
            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();
            $tabla->string('estado', 16)->default('pendiente');
            $tabla->uuid('profesional_id')->nullable();
            $tabla->uuid('procedimiento_id')->nullable();
            $tabla->date('fecha_prevista')->nullable();
            $tabla->timestampTz('fecha_realizada')->nullable();
            $tabla->unsignedInteger('presupuesto_clp')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('plan_tratamiento_id')->references('id')->on('planes_tratamiento')->cascadeOnDelete();
            $tabla->foreign('pieza_id')->references('id')->on('piezas')->restrictOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->nullOnDelete();
            $tabla->foreign('procedimiento_id')->references('id')->on('procedimientos')->nullOnDelete();

            $tabla->index(['tenant_id', 'plan_tratamiento_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapas_plan');
    }
};