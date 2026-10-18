<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Gravedad de una alergia registrada.
 *
 * No se inventa una escala propia: se usan los grados del Manejo de Reacción
 * Alérgica en el consultorio dental (MARC), que es la escala clínica de uso
 * corriente en odontología y distingue cuatro grados de reacción.
 *
 * IMPORTANTE: esta aplicación NO decide el grado. Lo escribe el profesional
 * que evaluó al paciente. El sistema solo lo muestra, lo usa para ordenar la
 * ficha y para que una alergia `Severa` exija una razón escrita todavía más
 * estricta antes de desbloquear la atención.
 */
enum SeveridadAlergia: string
{
    case Leve = 'leve';
    case Moderada = 'moderada';
    case Severa = 'severa';
    case Anafilactica = 'anafilactica';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Leve => 'Leve',
            self::Moderada => 'Moderada',
            self::Severa => 'Severa',
            self::Anafilactica => 'Anafiláctica',
        };
    }

    /**
     * True si la reacción registrada puede comprometer la vida.
     *
     * Es el criterio que decide que una alergia no se muestra como una nota
     * al pie de la ficha, sino como un bloqueo de la agenda. Ver
     * `docs/07-SEGURIDAD-Y-LEY-19628.md`.
     */
    public function puedeSerFatal(): bool
    {
        return in_array($this, [self::Severa, self::Anafilactica], true);
    }

    /**
     * Orden de urgencia, de menor a mayor. Se usa para ordenar la lista de
     * alergias de un paciente sin mezclar esto con el orden alfabético de la
     * sustancia.
     */
    public function urgencia(): int
    {
        return match ($this) {
            self::Leve => 1,
            self::Moderada => 2,
            self::Severa => 3,
            self::Anafilactica => 4,
        };
    }
}