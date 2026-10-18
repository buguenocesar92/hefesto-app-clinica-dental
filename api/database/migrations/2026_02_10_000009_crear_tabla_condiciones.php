<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condiciones de salud del paciente, y no solo alergias.
 *
 * En odontología hay condiciones que cambian la forma de atender aunque no
 * tengan nada que ver con un fármaco: anticoagulación, diabetes
 * descompensada, hipertensión no controlada.
 *
 * `bloquea_atencion` existe porque no todas bloquean igual. Una caries
 * odontológica en un paciente diabético se atiende, con las precauciones que
 * corresponda. Un paciente con anticoagulación sin ajuste no se atiende sin
 * que el tratante lo decida por escrito. El sistema no adivina: el
 * profesional marca qué bloquea y qué no, y el bloqueo se respeta igual que
 * el de las alergias.
 *
 * `requiere_autorizacion_especial` es para condiciones donde la atención
 * necesita un documento del médico tratante, por ejemplo la acreditación de
 * un ajuste de anticoagulación. Esta versión guarda la referencia del
 * documento pero NO sube ni escanea el archivo: declarado como pendiente en
 * `docs/10-PRUEBAS-Y-ESTADO.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condiciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('paciente_id');
            $tabla->uuid('profesional_id')->nullable();
            $tabla->string('condicion', 160);
            $tabla->text('detalle')->nullable();
            $tabla->boolean('bloquea_atencion')->default(false);
            $tabla->boolean('requiere_autorizacion_especial')->default(false);
            $tabla->string('referencia_documento', 240)->nullable();
            $tabla->boolean('activa')->default(true);
            $tabla->date('fecha_registro');
            $tabla->date('fecha_cierre')->nullable();
            $tabla->string('motivo_cierre', 240)->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->nullOnDelete();
            $tabla->index(['tenant_id', 'paciente_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condiciones');
    }
};