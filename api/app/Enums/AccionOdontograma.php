<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué se hizo con el historial de un paciente en una petición.
 *
 * Se registra en `accesos_odontograma`, con el mismo criterio que la clínica
 * general: **todo** intento queda anotado, permitido o denegado.
 */
enum AccionOdontograma: string
{
    case VerEstadoActual = 'ver_estado_actual';
    case VerCronologia = 'ver_cronologia';
    case RegistrarIntervencion = 'registrar_intervencion';
    case VerPlan = 'ver_plan';
    case EditarPlan = 'editar_plan';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VerEstadoActual => 'Ver el estado actual del odontograma',
            self::VerCronologia => 'Ver la cronología de una pieza',
            self::RegistrarIntervencion => 'Registrar una intervención',
            self::VerPlan => 'Ver un plan de tratamiento',
            self::EditarPlan => 'Crear o modificar un plan de tratamiento',
        };
    }
}