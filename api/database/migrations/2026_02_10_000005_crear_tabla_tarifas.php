<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifario del consultorio.
 *
 * UNA TARIFA ES UN DATO, NO UNA FACTURA
 * --------------------------------------
 * Esta aplicación no factura. La tabla guarda el precio en pesos chilenos que
 * el consultorio cobraba por un procedimiento, con la fecha desde la que
 * rigió. El tarifario no se borra cuando sube el precio: se agrega una fila
 * nueva con `vigente_desde` posterior, para que una intervención de 2019
 * siga mostrando lo que se cobró ese día.
 *
 * El precio va en CLP como entero. No se usa punto flotante para plata: un
 * `float` con centavos redondea mal, y esas diferencias se acumulan justo en
 * la columna que el consultorio compara con su propia caja. Para pagos en
 * cuotas o en dólares tendría que ser decimal exacto, pero el tarifario de
 * un consultorio chileno es en pesos enteros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('procedimiento_id');
            $tabla->string('nombre', 120);
            $tabla->unsignedInteger('precio_clp');
            $tabla->date('vigente_desde');
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->foreign('procedimiento_id')->references('id')->on('procedimientos')->restrictOnDelete();
            $tabla->index(['tenant_id', 'procedimiento_id', 'vigente_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas');
    }
};