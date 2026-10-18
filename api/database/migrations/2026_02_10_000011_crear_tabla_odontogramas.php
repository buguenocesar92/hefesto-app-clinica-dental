<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odontogramas: el mapa de un paciente.
 *
 * Hay uno por paciente. Es la cabecera del mapa y casi no lleva datos: la
 * información real está en `piezas` (el estado de hoy) y en
 * `intervenciones_pieza` (la cronología completa).
 *
 * Existe como tabla propia, y no como una columna en `pacientes`, por una
 * razón concreta: el odontograma tiene su propia fecha de alta y su propia
 * bitácora de acceso. Cuando un consultorio migra un cuaderno de papel, se
 * registra cuándo se hizo esa migración y desde ese momento el mapa tiene
 * responsable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odontogramas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('paciente_id');
            $tabla->date('fecha_alta');
            $tabla->string('origen', 32)->default('nuevo');
            $tabla->string('notas', 400)->nullable();
            $tabla->timestampTz('ultima_intervencion_at')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();

            // Un solo odontograma por paciente. La unicidad va con el
            // paciente y no con el tenant porque el paciente ya pertenece a
            // un único tenant.
            $tabla->unique('paciente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odontogramas');
    }
};