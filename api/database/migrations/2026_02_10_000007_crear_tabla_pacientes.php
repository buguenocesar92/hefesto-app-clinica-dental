<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pacientes del consultorio.
 *
 * QUÉ HAY ACÁ Y QUÉ NO
 * ---------------------
 * Acá hay identificación: nombre, RUT, contacto. Eso lo ve recepción, porque
 * recepción necesita saber a quién agenda.
 *
 * NO hay acá nada del historial odontológico. El odontograma, la cronología de
 * las piezas y los planes viven en `odontogramas`, `piezas`,
 * `intervenciones_pieza` y `planes_tratamiento`, que son tablas separadas con
 * sus propias políticas de permiso y su propia bitácora de acceso. La razón
 * es que el estado de una pieza y una alergia son datos de salud: no son lo
 * mismo que un teléfono.
 *
 * `ultimo_control_fecha` NO se guarda. El tiempo sin control se calcula
 * sobre las citas atendidas y sobre la última intervención registrada, en
 * `App\Services\Pacientes\TiempoSinControl`. Una columna con esa fecha
 * quedaría mala al día siguiente y nadie se entera de que dejó de
 * actualizarse. Ver docs/02.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('rut', 16)->nullable();
            $tabla->string('nombres', 80);
            $tabla->string('apellidos', 80);
            $tabla->date('fecha_nacimiento')->nullable();
            $tabla->string('sexo', 16)->default('no_informado');
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->string('direccion', 240)->nullable();
            $tabla->string('prevision', 120)->nullable();
            $tabla->text('notas_administrativas')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('consultorios')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'apellidos', 'nombres']);
            $tabla->index(['tenant_id', 'rut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};