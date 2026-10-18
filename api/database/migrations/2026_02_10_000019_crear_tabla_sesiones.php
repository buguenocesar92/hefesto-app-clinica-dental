<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de sesiones de Laravel.
 *
 * NO LLEVA RLS, y no es un olvido: es como en el resto de las aplicaciones
 * de Hefesto. La sesión es del servidor y no de un consultorio; a lo sumo
 * guarda el identificador del consultorio como texto para poder invalidar
 * las sesiones de un consultorio que se da de baja.
 *
 * El identificador del consultorio va en `payload` como JSON, no como
 * columna, para no crear la tentación de filtrar por ahí. La RLS protege
 * datos de negocio; las sesiones no son datos de negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();
            $tabla->foreignId('user_id')->nullable()->index();
            $tabla->string('ip_address', 45)->nullable();
            $tabla->text('user_agent')->nullable();
            $tabla->text('payload');
            $tabla->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};