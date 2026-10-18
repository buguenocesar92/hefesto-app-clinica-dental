<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sexo de registro del paciente.
 *
 * No es un dato de la historia clínica: es de identificación, va en la ficha
 * del paciente y lo ve recepción. En odontología se usa poco (la anatomía
 * bucodental no depende de él), pero el consultorio lo pide para la ficha y
 * para los estudios anonimizados.
 */
enum Sexo: string
{
    case Femenino = 'femenino';
    case Masculino = 'masculino';
    case Otro = 'otro';
    case NoInformado = 'no_informado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Femenino => 'Femenino',
            self::Masculino => 'Masculino',
            self::Otro => 'Otro',
            self::NoInformado => 'No informado',
        };
    }
}