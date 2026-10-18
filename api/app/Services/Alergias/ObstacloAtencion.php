<?php

declare(strict_types=1);

namespace App\Services\Alergias;

use App\Models\Alergia;
use App\Models\Condicion;

/**
 * Un antecedente que impide agendar, con su origen.
 *
 * Es una estructura de solo lectura: se arma al revisar y se usa para
 * responder 422 y para pintar la pantalla de bloqueo. No se guarda en la base
 * porque los antecedentes que la producen son las alergias y las condiciones,
 * que sí se guardan.
 */
final class ObstacloAtencion
{
    private function __construct(
        public readonly ?string $alergiaId,
        public readonly ?string $condicionId,
        public readonly string $sustancia,
        public readonly string $reaccion,
        public readonly string $gravedad,
        public readonly bool $esAlergia,
    ) {}

    public static function deAlergia(Alergia $alergia): self
    {
        return new self(
            alergiaId: $alergia->id,
            condicionId: null,
            sustancia: $alergia->sustancia,
            reaccion: $alergia->reaccion,
            gravedad: $alergia->severidad->etiqueta(),
            esAlergia: true,
        );
    }

    public static function deCondicion(Condicion $condicion): self
    {
        return new self(
            alergiaId: null,
            condicionId: $condicion->id,
            sustancia: $condicion->condicion,
            reaccion: (string) ($condicion->detalle ?? 'sin detalle registrado'),
            gravedad: 'condición de salud',
            esAlergia: false,
        );
    }

    /**
     * Línea para la pantalla y para el cuerpo del error.
     *
     * Dice qué es, qué pasó y cuán grave fue. Un "tiene alergias" sin más no
     * ayuda a decidir nada.
     */
    public function descripcion(): string
    {
        if (! $this->esAlergia) {
            return sprintf('Condición "%s" marcada como bloqueante (%s).', $this->sustancia, $this->reaccion);
        }

        return sprintf(
            'Alergia activa a %s: %s (gravedad %s).',
            $this->sustancia,
            $this->reaccion,
            $this->gravedad,
        );
    }

    /** Identificador del registro que hay que desbloquear. */
    public function registroId(): string
    {
        return (string) ($this->alergiaId ?? $this->condicionId);
    }
}