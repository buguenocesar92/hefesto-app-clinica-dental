<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Insumos del consultorio.
 *
 * `stock_actual` y `stock_minimo` son un conteo manual: esta versión NO lleva
 * control de movimientos de bodega ni calcula consumo por procedimiento.
 * Es un casillero, no un inventario. Queda anotado como pendiente en
 * `docs/10-PRUEBAS-Y-ESTADO.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insumos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('codigo', 24);
            $tabla->string('nombre', 120);
            $tabla->string('unidad', 24)->default('unidad');
            $tabla->integer('stock_actual')->default(0);
            $tabla->integer('stock_minimo')->default(0);
            $tabla->date('fecha_ultima_carga')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insumos');
    }
};