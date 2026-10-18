<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoPieza;
use App\Services\Odontograma\MaquinaDeEstadosPieza;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La maquina de estados de la pieza dental.
 *
 * POR QUE ESTA MAQUINA EXISTE
 * --------------------------
 * El estado de una pieza no se edita: se cambia registrando una intervencion. Y
 * no todas las combinaciones tienen sentido en odontologia. Sin esta maquina, un
 * `UPDATE` podria dejar una pieza extraida y sana al mismo tiempo, o devolver a
 * la boca una pieza que nunca estuvo.
 *
 * Una historia clinica que se puede dejar en cualquier estado no es una historia
 * clinica. Ver docs/02.
 *
 * LO QUE LA MAQUINA RESTRINGE, Y LO QUE NO
 * -----------------------------------------
 * Restringe cuatro cosas:
 *
 *   1. Una pieza `ausente` es terminal: nunca estuvo, no hay historia que reponer.
 *   2. Una pieza con `implante` es terminal en esta version.
 *   3. Desde `extraida` solo se sale a un reemplazo (implante, protesis o
 *      puente). Volver a un estado natural borraria el hecho de la extraccion.
 *   4. Quedarse en el mismo estado esta permitido: repetir un sellante o rehacer
 *      una obturacion es algo normal.
 *
 * NO restringe el resto. Por ejemplo, `sano` a `obturado` esta permitido: el
 * sistema no juzga si el diagnostico de caries estaba bien puesto, porque eso es
 * criterio clinico del profesional, no una regla del software. Ver docs/07.
 */
class MaquinaDeEstadosPiezaTest extends TestCase
{
    /**
     * @return list<array{0: EstadoPieza}>
     */
    public static function destinosDesdeSano(): array
    {
        return [
            'sano a sano' => [EstadoPieza::Sano],
            'sano a ausente' => [EstadoPieza::Ausente],
            'sano a caries' => [EstadoPieza::Caries],
            'sano a fractura' => [EstadoPieza::Fractura],
            'sano a sellante' => [EstadoPieza::Sellante],
            'sano a extraccion' => [EstadoPieza::PorExtraer],
            'sano a implante' => [EstadoPieza::Implante],
        ];
    }

    #[DataProvider('destinosDesdeSano')]
    public function test_desde_una_pieza_sana_se_puede_llegar_a_los_estados_listados(EstadoPieza $destino): void
    {
        $this->assertTrue(
            MaquinaDeEstadosPieza::permite(EstadoPieza::Sano, $destino),
            sprintf('De sano a %s deberia estar permitido.', $destino->value),
        );
    }

    /**
     * `ausente` es terminal: la pieza nunca estuvo en la boca.
     *
     * Si en realidad se extrajo, eso es un hecho distinto y hay que registrarlo
     * como extraccion, que queda en la cronologia.
     */
    public function test_una_pieza_ausente_es_terminal(): void
    {
        foreach (EstadoPieza::cases() as $estado) {
            if ($estado === EstadoPieza::Ausente) {
                continue;
            }

            $this->assertFalse(
                MaquinaDeEstadosPieza::permite(EstadoPieza::Ausente, $estado),
                sprintf('De ausente a %s no deberia estar permitido.', $estado->value),
            );
        }
    }

    public function test_un_implante_es_terminal_en_esta_version(): void
    {
        foreach (EstadoPieza::cases() as $estado) {
            if ($estado === EstadoPieza::Implante) {
                continue;
            }

            $this->assertFalse(
                MaquinaDeEstadosPieza::permite(EstadoPieza::Implante, $estado),
                sprintf('De implante a %s no deberia estar permitido.', $estado->value),
            );
        }
    }

    /**
     * Desde `extraida` solo se sale a un reemplazo.
     */
    public function test_desde_extraida_solo_se_sale_a_un_reemplazo(): void
    {
        $reemplazos = [EstadoPieza::Implante, EstadoPieza::Protesis, EstadoPieza::Puente];

        foreach ($reemplazos as $reemplazo) {
            $this->assertTrue(
                MaquinaDeEstadosPieza::permite(EstadoPieza::Extraida, $reemplazo),
                sprintf('De extraida a %s deberia estar permitido: es un reemplazo.', $reemplazo->value),
            );
        }

        $noReemplazos = [
            EstadoPieza::Sano,
            EstadoPieza::Caries,
            EstadoPieza::Obturado,
            EstadoPieza::Corona,
            EstadoPieza::Endodoncia,
            EstadoPieza::Sellante,
            EstadoPieza::Fractura,
        ];

        foreach ($noReemplazos as $estado) {
            $this->assertFalse(
                MaquinaDeEstadosPieza::permite(EstadoPieza::Extraida, $estado),
                sprintf(
                    'De extraida a %s no deberia estar permitido: devolver una pieza extraida a un '
                    . 'estado natural borraria el hecho de la extraccion.',
                    $estado->value,
                ),
            );
        }
    }

    public function test_una_pieza_por_extraer_puede_extraerse(): void
    {
        $this->assertTrue(MaquinaDeEstadosPieza::permite(EstadoPieza::PorExtraer, EstadoPieza::Extraida));
    }

    /**
     * Quedarse en el mismo estado no es una transicion: es repetir el trabajo.
     */
    public function test_toda_pieza_puede_quedarse_en_su_propio_estado(): void
    {
        foreach (EstadoPieza::cases() as $estado) {
            $this->assertTrue(
                MaquinaDeEstadosPieza::permite($estado, $estado),
                sprintf('Una pieza en %s puede seguir en %s.', $estado->value, $estado->value),
            );
        }
    }

    /**
     * El sistema NO juzga el criterio clinico del profesional.
     *
     * De sano a obturado esta permitido. Puede parecer una laguna y es una
     * decision: decidir si un diagnostico estaba bien puesto es un criterio
     * clinico, y una maquina de estados que lo impusiera seria inventar
     * odontologia. Ver docs/07.
     */
    public function test_la_maquina_no_juzga_el_criterio_clinico(): void
    {
        $this->assertTrue(MaquinaDeEstadosPieza::permite(EstadoPieza::Sano, EstadoPieza::Obturado));
    }

    /**
     * `exigir` lanza con un mensaje que nombra la pieza.
     *
     * El mensaje va a la pantalla del profesional: no es un codigo de error, es
     * la explicacion de por que el sistema no lo dejo hacer lo que queria.
     */
    public function test_exigir_lanza_con_un_mensaje_que_nombra_la_pieza(): void
    {
        try {
            MaquinaDeEstadosPieza::exigir(EstadoPieza::Extraida, EstadoPieza::Obturado, 16);

            $this->fail('Se esperaba una excepcion: no se puede obturar una pieza extraida.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('16', $e->getMessage());
            $this->assertStringContainsString('extraída', $e->getMessage());
        }
    }

    public function test_exigir_no_lanza_una_transicion_valida(): void
    {
        MaquinaDeEstadosPieza::exigir(EstadoPieza::Caries, EstadoPieza::Obturado, 26);

        $this->assertTrue(true, 'La transicion de caries a obturado es legitima.');
    }

    public function test_los_estados_fuera_de_la_boca_estan_marcados(): void
    {
        $this->assertTrue(EstadoPieza::Extraida->estaFueraDeLaBoca());
        $this->assertTrue(EstadoPieza::Ausente->estaFueraDeLaBoca());
        $this->assertFalse(EstadoPieza::Obturado->estaFueraDeLaBoca());
        $this->assertFalse(EstadoPieza::Sano->estaFueraDeLaBoca());
    }

    public function test_los_reemplazos_estan_marcados(): void
    {
        $this->assertTrue(EstadoPieza::Implante->esReemplazo());
        $this->assertTrue(EstadoPieza::Protesis->esReemplazo());
        $this->assertTrue(EstadoPieza::Puente->esReemplazo());
        $this->assertFalse(EstadoPieza::Obturado->esReemplazo());
        $this->assertFalse(EstadoPieza::Corona->esReemplazo());
    }

    /**
     * La matriz completa, para tenerla a la vista.
     *
     * Si TODAS las combinaciones fueran legales, la maquina no estaria
     * restringiendo nada, y esta prueba lo dice.
     */
    public function test_la_maquina_restringe_de_veras(): void
    {
        $legales = 0;
        $prohibidas = 0;

        foreach (EstadoPieza::cases() as $anterior) {
            foreach (EstadoPieza::cases() as $nuevo) {
                if (MaquinaDeEstadosPieza::permite($anterior, $nuevo)) {
                    $legales++;
                } else {
                    $prohibidas++;
                }
            }
        }

        $this->assertGreaterThan(0, $prohibidas, 'La maquina no prohibe ninguna transicion.');
        $this->assertGreaterThan(0, $legales, 'La maquina no permite ninguna transicion.');

        // Coherencia con lo que la clase declara: 14 estados, 196 combinaciones.
        $this->assertCount(14, EstadoPieza::cases());
        $this->assertSame(14 * 14, $legales + $prohibidas);
    }
}