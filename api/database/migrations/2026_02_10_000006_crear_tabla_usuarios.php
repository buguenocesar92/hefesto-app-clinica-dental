<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios de la aplicación, con su rol dentro del consultorio.
 *
 * La identidad la trae Authentik: acá solo se guarda el correo con el que la
 * persona entró, su nombre y su rol. El `id` de esta tabla NO es el id de
 * Authentik: son cosas distintas y mantenerlas separadas evita que un cambio
 * en el proveedor de identidad rompa el historial clínico, que no se puede
 * rehacer.
 *
 * `profesional_id` es opcional: recepción y administración no son
 * profesionales. Si está lleno, la persona puede registrar intervenciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('profesional_id')->nullable();
            $tabla->string('nombre', 160);
            $tabla->string('correo', 160);
            $tabla->string('rol', 24)->default('recepcion');
            $tabla->string('idioma_authentik', 160)->nullable();
            $tabla->timestampTz('ultimo_acceso')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->nullOnDelete();
            $tabla->unique(['tenant_id', 'correo']);
            $tabla->index(['tenant_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};