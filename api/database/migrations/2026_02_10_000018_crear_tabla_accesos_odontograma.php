<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de accesos al historial odontológico.
 *
 * Sin excepciones: TODO intento queda anotado, permitido o denegado. Es la
 * misma regla que aplica la clínica general, y por el mismo motivo: en
 * odontología un historial puede revelar información que el paciente no
 * quiere que vea un tercero: el motivo de una extracción, los nombres de
 * los profesionales que lo trataron, o un tratamiento que el paciente nunca
 * menciona en su vida común. Todo eso queda escrito y es información de
 * salud bajo la Ley 19.628.
 *
 * `resultado` distingue permitido, denegado y el intento sin sesión.
 *
 * Esta tabla crece para siempre y es de solo lectura: no hay ruta de la API
 * para escribir a mano, ni para borrar. Ver `docs/08-RESPALDOS.md` para el
 * tamaño esperado y la política de conservación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_odontograma', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('usuario_id')->nullable();
            $tabla->uuid('paciente_id')->nullable();
            $tabla->smallInteger('numero_fdi')->nullable();
            $tabla->string('accion', 32);
            $tabla->string('resultado', 16);
            $tabla->string('metodo_http', 8)->nullable();
            $tabla->string('ruta', 240)->nullable();
            $tabla->string('nombre_ruta', 120)->nullable();
            $tabla->string('direccion_ip', 45)->nullable();
            $tabla->string('agente_usuario', 500)->nullable();
            $tabla->integer('codigo_respuesta')->nullable();
            $tabla->timestampTz('momento');

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->nullOnDelete();

            $tabla->index(['tenant_id', 'momento']);
            $tabla->index(['tenant_id', 'paciente_id', 'momento']);
            $tabla->index(['tenant_id', 'usuario_id', 'momento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_odontograma');
    }
};