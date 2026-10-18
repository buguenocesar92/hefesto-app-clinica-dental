<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de tratamiento.
 *
 * UN PLAN NO ES UNA ACCIÓN HECHA
 * ------------------------------
 * El plan es lo que se va a hacer. Lo que se hizo está en
 * `intervenciones_pieza`. Mezclarlos es el error clásico: se marca una etapa
 * como realizada y el odontograma no cambia, o al revés, se interviene una
 * pieza y queda un plan que dice que estaba pendiente.
 *
 * `presupuesto_clp` es un entero de referencia: es lo que se le cotizó al
 * paciente, no una factura. Esta aplicación no factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes_tratamiento', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('paciente_id');
            $tabla->uuid('profesional_id');
            $tabla->string('nombre', 160);
            $tabla->text('diagnostico')->nullable();
            $tabla->string('estado', 16)->default('borrador');
            $tabla->unsignedInteger('presupuesto_clp')->nullable();
            $tabla->date('fecha_inicio');
            $tabla->date('fecha_fin')->nullable();
            $tabla->date('fecha_presentacion')->nullable();
            $tabla->date('fecha_aprobacion')->nullable();
            $tabla->text('observaciones')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->restrictOnDelete();

            $tabla->index(['tenant_id', 'paciente_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes_tratamiento');
    }
};