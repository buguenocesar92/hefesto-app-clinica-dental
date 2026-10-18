<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de procedimientos del consultorio.
 *
 * ESTA TABLA ES LA QUE RESPONDE A "NO TODOS OCUPAN LO MISMO"
 * ---------------------------------------------------------
 * `duracion_minutos` es la duración real de la atención, y la agenda la
 * calcula desde acá. Agendar todo en bloques iguales es lo que hace que una
 * jornada se atrase: una profilaxis de 40 minutos y una endodoncia de 90
 * minutos no pueden ocupar el mismo bloque de 30.
 *
 * La duración es la que declara el consultorio, no una tabla nacional: cada
 * profesional trabaja a su ritmo y con su instrumental. No se inventa un
 * catálogo oficial de duraciones.
 *
 * `es_quirurgico` marca los procedimientos que requieren un campo más
 * despejado y aviso al paciente. No bloquea la agenda: informa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedimientos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('codigo', 24);
            $tabla->string('nombre', 120);
            $tabla->string('categoria', 60)->default('general');
            $tabla->unsignedSmallInteger('duracion_minutos')->default(30);
            $tabla->boolean('es_quirurgico')->default(false);
            $tabla->boolean('requiere_anamnesis')->default(true);
            $tabla->text('descripcion')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedimientos');
    }
};