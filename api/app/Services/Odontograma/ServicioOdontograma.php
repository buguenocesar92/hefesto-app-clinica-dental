<?php

declare(strict_types=1);

namespace App\Services\Odontograma;

use App\Enums\EstadoPieza;
use App\Models\IntervencionPieza;
use App\Models\Odontograma;
use App\Models\Paciente;
use App\Models\Pieza;
use App\Models\Profesional;
use App\Models\Procedimiento;
use App\Models\PlanTratamiento;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Escritura del odontograma: intervención y estado, juntos o ninguno.
 *
 * LA REGLA
 * --------
 * Un estado de pieza nunca cambia solo. Cada cambio va con una fila nueva en
 * `intervenciones_pieza`, y las dos cosas ocurren en la misma transacción. Si
 * la intervención falla, el estado no cambia; si el estado falla, la
 * intervención tampoco queda.
 *
 * Esa regla está en tres lugares, y no por redundancia inútil:
 *
 *  1. Aquí, en `registrar()`.
 *  2. En `MaquinaDeEstadosPieza`, que decide qué transición es legítima.
 *  3. En la base, con los disparadores de
 *     `2026_02_10_000021_proteger_cronologia_piezas.php`, que rechazan el
 *     cambio de estado sin intervención y la restauración de una pieza
 *     extraída.
 *
 * El punto 3 existe porque 1 y 2 se pueden saltar. Un script de importación
 * de un cuaderno de papel escribe en `piezas` y no pasa por este servicio.
 * Una regla de historia clínica que depende del cuidado del programador no
 * es una regla.
 */
class ServicioOdontograma
{
    /**
     * Registra una intervención sobre una pieza.
     *
     * @param  array{
     *     paciente: Paciente,
     *     numero_fdi: int,
     *     estado_resultante: EstadoPieza,
     *     descripcion: string,
     *     profesional: Profesional,
     *     usuario: Usuario,
     *     fecha?: Carbon,
     *     procedimiento?: Procedimiento|null,
     *     plan?: PlanTratamiento|null,
     *     notas?: string|null
     * }  $datos
     *
     * @throws InvalidArgumentException si el número no es una pieza válida o
     *                                  si la transición no está permitida.
     */
    public function registrar(array $datos): IntervencionPieza
    {
        if (! PiezaFdi::esValido($datos['numero_fdi'])) {
            throw new InvalidArgumentException(sprintf(
                'El número %s no es una pieza dentaria válida en notación FDI. '
                . 'Las piezas permanentes van de 11 a 48: el primer dígito es el cuadrante y el segundo la pieza.',
                var_export($datos['numero_fdi'], true),
            ));
        }

        return DB::transaction(function () use ($datos): IntervencionPieza {
            $odontograma = $this->odontogramaDe($datos['paciente']);
            $pieza = $this->piezaDe($odontograma, $datos['numero_fdi']);

            $estadoAnterior = $pieza->estado;
            $estadoResultante = $datos['estado_resultante'];

            // La misma máquina que usa el test unitario. Si la transición no
            // es legítima, se corta acá y no se escribe nada.
            MaquinaDeEstadosPieza::exigir($estadoAnterior, $estadoResultante, $datos['numero_fdi']);

            $fechaOcurrencia = $datos['fecha'] ?? now();

            $intervencion = IntervencionPieza::query()->create([
                'tenant_id' => (string) $datos['paciente']->tenant_id,
                'paciente_id' => $datos['paciente']->id,
                'odontograma_id' => $odontograma->id,
                'pieza_id' => $pieza->id,
                'numero_fdi' => $pieza->numero_fdi,
                'profesional_id' => $datos['profesional']->id,
                'procedimiento_id' => $datos['procedimiento']?->id,
                'plan_tratamiento_id' => $datos['plan']?->id,
                'fecha' => $fechaOcurrencia,
                // `fecha_registro` es otra cosa: cuándo se escribió acá. Al
                // migrar un cuaderno viejo, estas dos fechas difieren en años.
                'fecha_registro' => now(),
                'descripcion' => $datos['descripcion'],
                'estado_anterior' => $estadoAnterior->value,
                'estado_resultante' => $estadoResultante->value,
                'registrado_por' => $datos['usuario']->id,
                'notas' => $datos['notas'] ?? null,
            ]);

            // Ahora sí, con la intervención ya insertada en esta misma
            // transacción, el estado puede moverse. El disparador
            // `piezas_exigen_cronologia` lo comprueba.
            $pieza->forceFill([
                'estado' => $estadoResultante,
                'estado_desde' => $fechaOcurrencia,
                'ultima_intervencion_fecha' => $fechaOcurrencia,
                'ultima_intervencion_id' => $intervencion->id,
            ])->save();

            $odontograma->forceFill([
                'ultima_intervencion_at' => $fechaOcurrencia,
            ])->save();

            return $intervencion->refresh();
        });
    }

    /**
     * Crea el odontograma del paciente con sus 32 piezas, todas en estado
     * `sano`.
     *
     * Se hace por piezas y no con un `INSERT` masivo a propósito: las 32 filas
     * necesitan `estado_desde`, y el alta de un mapa es un hecho que tiene
     * fecha propia. El estado `sano` de una pieza que nadie ha evaluado es una
     * afirmación; queda la fecha para que se sepa cuándo se hizo.
     *
     * Las piezas que el paciente no tiene de partida (agenesia, extracción
     * previa a la migración) se dejan en `sano` y se corrigen con la primera
     * intervención. Ponerlas en `ausente` automáticamente sería inventar
     * información clínica.
     */
    public function crearOdontograma(Paciente $paciente, string $origen = 'nuevo', ?Carbon $fechaAlta = null): Odontograma
    {
        return DB::transaction(function () use ($paciente, $origen, $fechaAlta): Odontograma {
            $existente = Odontograma::query()
                ->where('paciente_id', $paciente->id)
                ->first();

            if ($existente !== null) {
                return $existente;
            }

            $fecha = $fechaAlta ?? now();

            $odontograma = Odontograma::query()->create([
                'tenant_id' => (string) $paciente->tenant_id,
                'paciente_id' => $paciente->id,
                'fecha_alta' => $fecha->toDateString(),
                'origen' => $origen,
            ]);

            foreach (PiezaFdi::todos() as $numero) {
                Pieza::query()->create([
                    'tenant_id' => (string) $paciente->tenant_id,
                    'odontograma_id' => $odontograma->id,
                    'paciente_id' => $paciente->id,
                    'numero_fdi' => $numero,
                    'estado' => EstadoPieza::Sano->value,
                    'estado_desde' => $fecha,
                ]);
            }

            return $odontograma;
        });
    }

    /**
     * Cronología de una pieza, de más antigua a más reciente.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, IntervencionPieza>
     */
    public function cronologiaDe(int $numeroFdi, string $pacienteId)
    {
        return IntervencionPieza::query()
            ->where('paciente_id', $pacienteId)
            ->where('numero_fdi', $numeroFdi)
            ->orderBy('fecha')
            ->orderBy('created_at')
            ->get();
    }

    /** Toda la cronología del paciente, de más antigua a más reciente. */
    public function cronologiaCompleta(string $pacienteId)
    {
        return IntervencionPieza::query()
            ->where('paciente_id', $pacienteId)
            ->orderBy('fecha')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Odontograma del paciente, creándolo si no existe.
     */
    public function odontogramaDe(Paciente $paciente): Odontograma
    {
        $odontograma = Odontograma::query()
            ->where('paciente_id', $paciente->id)
            ->first();

        if ($odontograma !== null) {
            return $odontograma;
        }

        return $this->crearOdontograma($paciente);
    }

    /**
     * Fila de la pieza, o excepción con un mensaje que el profesional entienda.
     */
    public function piezaDe(Odontograma $odontograma, int $numeroFdi): Pieza
    {
        $pieza = Pieza::query()
            ->where('odontograma_id', $odontograma->id)
            ->where('numero_fdi', $numeroFdi)
            ->first();

        if ($pieza === null) {
            throw new InvalidArgumentException(sprintf(
                'La pieza %d no está en el odontograma de este paciente.',
                $numeroFdi,
            ));
        }

        return $pieza;
    }
}