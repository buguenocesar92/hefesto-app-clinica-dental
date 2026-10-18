<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intervenciones por pieza: LA CRONOLOGÍA ODONTOLÓGICA.
 *
 * EL PRODUCTO ESTÁ EN ESTA TABLA
 * ------------------------------
 * "Una pieza tratada en 2019 y el mismo molar extraído en 2024 son dos hechos
 * distintos, y el segundo sin el primero es información inútil."
 *
 * Por eso cada fila es un hecho con su propia fecha, y las filas no se
 * tocan. Nunca. El `UPDATE` y el `DELETE` están bloqueados por disparadores
 * en la migración `2026_02_10_000021_proteger_cronologia_piezas.php`. No es
 * una convención: es una restricción de la base.
 *
 * `fecha` Y `fecha_registro` NO SON LO MISMO
 * ------------------------------------------
 * - `fecha`: cuándo ocurrió el hecho. Puede ser de 2019.
 * - `fecha_registro`: cuándo alguien lo escribió en el sistema. Puede ser
 *   hoy, al migrar un cuaderno de papel.
 *
 * Confundirlas es como se pierde la historia de un consultorio que lleva
 * veinte años funcionando: si todo queda con la fecha de digitación, el
 * profesional ve que el paciente fue tratado hace seis meses y no en 2019.
 *
 * `estado_anterior` y `estado_resultante`
 * --------------------------------------
 * Cada intervención guarda el estado antes y después. Con eso, la cronología
 * se lee sola: no hay que deducir nada, y un diente que pasó por obturado,
 * endodoncia, corona y extracción lo dice con las fechas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervenciones_pieza', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('paciente_id');
            $tabla->uuid('odontograma_id');
            $tabla->uuid('pieza_id');

            // Desnormalizado a propósito. La cronología de una pieza se lee
            // sin necesitar el `JOIN` con `piezas`, y el número queda escrito
            // en el hecho mismo: si algún día la pieza se renumera, el hecho
            // conserva el número con el que ocurrió.
            $tabla->smallInteger('numero_fdi');

            $tabla->uuid('profesional_id');
            $tabla->uuid('procedimiento_id')->nullable();
            $tabla->uuid('plan_tratamiento_id')->nullable();
            $tabla->uuid('etapa_plan_id')->nullable();

            // Cuándo ocurrió y cuándo se escribió. Ver la nota de arriba.
            $tabla->timestampTz('fecha');
            $tabla->timestampTz('fecha_registro');

            $tabla->text('descripcion');
            $tabla->string('estado_anterior', 24)->nullable();
            $tabla->string('estado_resultante', 24);

            // Quién escribió la fila, que no siempre es el profesional que
            // hizo la intervención: un asistente puede registrar el dato que
            // le dio el odontólogo.
            $tabla->uuid('registrado_por')->nullable();

            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();
            $tabla->foreign('odontograma_id')->references('id')->on('odontogramas')->cascadeOnDelete();
            $tabla->foreign('pieza_id')->references('id')->on('piezas')->restrictOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->restrictOnDelete();
            $tabla->foreign('procedimiento_id')->references('id')->on('procedimientos')->nullOnDelete();
            $tabla->foreign('registrado_por')->references('id')->on('usuarios')->nullOnDelete();

            // La consulta que más se hace: la historia de una pieza, en orden.
            $tabla->index(['tenant_id', 'paciente_id', 'numero_fdi', 'fecha']);

            // Y la segunda: toda la historia del paciente, sin filtrar por
            // pieza. Es la que arma el informe de "qué se le hizo y cuándo".
            $tabla->index(['tenant_id', 'paciente_id', 'fecha']);

            $tabla->index(['tenant_id', 'profesional_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervenciones_pieza');
    }
}