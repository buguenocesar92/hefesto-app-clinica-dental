<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profesionales del consultorio.
 *
 * `registro_profesional` es texto libre a propósito: el registro de
 * profesionales dental de Chile no tiene una API pública de consulta que se
 * pueda consumir acá, y esta versión NO valida el registro contra ninguna
 * fuente oficial. Poner una validación inventada sería peor que ninguna:
 * dejaría pasar registros falsos con apariencia de buenos. Queda declarado
 * como pendiente en `docs/10-PRUEBAS-Y-ESTADO.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profesionales', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            $tabla->string('especialidad', 120)->nullable();
            $tabla->string('registro_profesional', 60)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('color_agenda', 7)->default('#2563eb');
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profesionales');
    }
};