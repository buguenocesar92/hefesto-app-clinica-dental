<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de los datos de ejemplo.
 *
 * QUE CORRE Y QUE NO
 * ------------------
 * Corre el consultorio de ejemplo: un consultorio, un administrador, una
 * recepcion, dos profesionales, un catalogo de procedimientos con su tarifario,
 * insumos y tres pacientes con antecedentes.
 *
 * NO crea ninguna identidad en Authentik. La identidad la emite el proveedor;
 * aca solo queda la copia de trabajo. Ver docs/05.
 *
 * Los datos personales son INVENTADOS y los RUT no existen en Chile. Ver la
 * advertencia de ConsultorioDeEjemploSeeder y el [06](../docs/06-PRIMER-PACIENTE.md).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ConsultorioDeEjemploSeeder::class,
        ]);

        $this->command?->newLine();
        $this->command?->info('Datos de ejemplo cargados.');
        $this->command?->warn('Datos personales INVENTADOS. Los RUT usados no existen en Chile.');
        $this->command?->info('Antes de usar esto con una persona real, lea docs/07.');
    }
}