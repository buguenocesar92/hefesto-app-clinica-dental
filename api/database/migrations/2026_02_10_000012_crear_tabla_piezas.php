<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Piezas: el estado ACTUAL de cada pieza dentaria.
 *
 * UNA PIEZA TIENE UNA CRONOLOGÍA, NO UN ESTADO
 * ---------------------------------------------
 * Esta tabla es **el estado**: lo que se lee hoy en la pantalla. La
 * cronología está en `intervenciones_pieza` y es acumulativa.
 *
 * La regla que sostiene todo el modelo:
 *
 *   NINGÚN `UPDATE` DE `estado` ES VÁLIDO POR SÍ SOLO.
 *
 * Para que el estado cambie tiene que existir una intervención nueva en la
 * cronología, en la misma transacción. Eso lo fuerza el disparador
 * `piezas_exigen_cronologia` de la migración
 * `2026_02_10_000021_proteger_cronologia_piezas.php`.
 *
 * `numero_fdi` va como entero de dos dígitos, en notación FDI (ISO 3950). El
 * `CHECK` de abajo es la lista real de las 32 piezas permanentes: si alguien
 * intenta guardar un 00 o un 55, la base lo rechaza. No es una validación
 * "de 11 a 48": es la lista.
 *
 * `ultima_intervencion_id` es un puntero de apoyo, no una fuente de verdad.
 * La fuente de verdad es la cronología; este campo existe para que la
 * pantalla del odontograma no tenga que traer la historia entera del
 * paciente para poder pintar el mapa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piezas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('odontograma_id');
            $tabla->uuid('paciente_id');
            $tabla->smallInteger('numero_fdi');
            $tabla->string('estado', 24)->default('sano');
            $tabla->timestampTz('estado_desde');
            $tabla->timestampTz('ultima_intervencion_fecha')->nullable();
            $tabla->uuid('ultima_intervencion_id')->nullable();
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('odontograma_id')->references('id')->on('odontogramas')->cascadeOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();

            // Una fila por pieza, por odontograma.
            $tabla->unique(['odontograma_id', 'numero_fdi']);

            // La lista real de piezas permanentes FDI. Las 32, una por una.
            $lista = implode(', ', array_map(
                static fn (int $n): string => (string) $n,
                range(11, 18)
            ));
            $lista .= ', ';
            $lista .= implode(', ', array_map(
                static fn (int $n): string => (string) $n,
                range(21, 28)
            ));
            $lista .= ', ';
            $lista .= implode(', ', array_map(
                static fn (int $n): string => (string) $n,
                range(31, 38)
            ));
            $lista .= ', ';
            $lista .= implode(', ', array_map(
                static fn (int $n): string => (string) $n,
                range(41, 48)
            ));

            $tabla->raw(
                "ALTER TABLE piezas ADD CONSTRAINT piezas_numero_fdi_valido "
                . "CHECK (numero_fdi IN ({$lista}))"
            );

            $tabla->raw(
                "ALTER TABLE piezas ADD CONSTRAINT piezas_estado_valido "
                . "CHECK (estado IN ('sano', 'ausente', 'caries', 'obturado', 'corona', "
                . "'endodoncia', 'sellante', 'fractura', 'indicacion_extraccion', "
                . "'por_extraer', 'extraida', 'implante', 'protesis', 'puente'))"
            );

            $tabla->index(['tenant_id', 'paciente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piezas');
    }
};