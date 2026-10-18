<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Sexo;
use App\Enums\EstadoCita;
use App\Enums\EstadoEtapa;
use App\Enums\EstadoPlan;
use App\Enums\RolDental;
use App\Services\Odontograma\PiezaFdi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Enumeraciones y notacion FDI.
 *
 * Son las piezas mas pequenas del sistema y las que mas se tocan a mano, asi
 * que conviene que tengan pruebas propias: un `case` mal escrito se descubre
 * tarde, cuando ya hay una fila en la base con ese valor.
 */
class EnumsYPiezaFdiTest extends TestCase
{
    /**
     * Los tres roles, y el equipo de Authentik que corresponde a cada uno.
     *
     * @return list<array{0: RolDental, 1: string}>
     */
    public static function roles(): array
    {
        return [
            [RolDental::Recepcion, 'Recepcion de ClinicaDental'],
            [RolDental::Profesional, 'Profesionales de ClinicaDental'],
            [RolDental::Administrador, 'Administradores de ClinicaDental'],
        ];
    }

    #[DataProvider('roles')]
    public function test_cada_rol_tiene_su_equipo_de_authentik(RolDental $rol, string $equipoEsperado): void
    {
        $this->assertSame($equipoEsperado, $rol->equipoAuthentik());
    }

    public function test_los_tres_roles_existen_y_son_distintos(): void
    {
        $valores = array_map(static fn (RolDental $rol): string => $rol->value, RolDental::cases());

        $this->assertSame(['recepcion', 'profesional', 'administrador'], $valores);
    }

    /**
     * Una cita anulada o en la que el paciente no asistio deja de ocupar agenda.
     *
     * Es lo que permite volver a agendar ese horario. Si un estado muerto
     * siguiera ocupando, un consultorio llenaria su agenda de huecos que nadie
     * puede usar.
     */
    public function test_los_estados_que_no_ocupan_agenda(): void
    {
        $this->assertTrue(EstadoCita::Anulada->ocupaAgenda() === false);
        $this->assertTrue(EstadoCita::NoAsistio->ocupaAgenda() === false);
        $this->assertTrue(EstadoCita::Agendada->ocupaAgenda());
        $this->assertTrue(EstadoCita::Confirmada->ocupaAgenda());
        $this->assertTrue(EstadoCita::Atendida->ocupaAgenda());
    }

    public function test_los_estados_que_son_un_hecho_consumado(): void
    {
        $this->assertTrue(EstadoCita::Atendida->esHecho());
        $this->assertTrue(EstadoCita::NoAsistio->esHecho());
        $this->assertFalse(EstadoCita::Agendada->esHecho());
        $this->assertFalse(EstadoCita::Anulada->esHecho());
    }

    /**
     * Solo un plan aprobado o en curso admite etapas nuevas.
     */
    public function test_cuando_un_plan_admite_etapas(): void
    {
        $this->assertTrue(EstadoPlan::Borrador->admiteEtapas());
        $this->assertTrue(EstadoPlan::Aprobado->admiteEtapas());
        $this->assertTrue(EstadoPlan::EnCurso->admiteEtapas());
        $this->assertFalse(EstadoPlan::Completado->admiteEtapas());
        $this->assertFalse(EstadoPlan::Rechazado->admiteEtapas());
    }

    public function test_una_etapa_realizada_u_omitida_es_un_hecho(): void
    {
        $this->assertTrue(EstadoEtapa::Realizada->esHecho());
        $this->assertTrue(EstadoEtapa::Omitida->esHecho());
        $this->assertFalse(EstadoEtapa::Pendiente->esHecho());
        $this->assertFalse(EstadoEtapa::Programada->esHecho());
    }

    /**
     * Existe "no informado" a proposito.
     *
     * Un paciente que no quiere declarar su sexo no es un paciente sin sexo: es
     * un paciente que no lo declaro. Si el campo fuera obligatorio, se
     * inventaria informacion.
     */
    public function test_existe_el_sexo_no_informado(): void
    {
        $this->assertSame('no_informado', Sexo::NoInformado->value);
        $this->assertCount(4, Sexo::cases());
    }

    /**
     * Las 32 piezas permanentes, y solo esas.
     *
     * @return list<array{0: int}>
     */
    public static function piezasValidas(): array
    {
        return array_map(static fn (int $n): array => [$n], range(11, 18) + range(21, 28) + range(31, 38) + range(41, 48));
    }

    #[DataProvider('piezasValidas')]
    public function test_las_32_piezas_permanentes_son_validas(int $numero): void
    {
        $this->assertTrue(
            PiezaFdi::esValido($numero),
            sprintf('La pieza %d deberia ser valida en notacion FDI.', $numero),
        );
    }

    /**
     * Numeros que NO son piezas.
     *
     * @return list<array{0: int}>
     */
    public static function numerosInvalidos(): array
    {
        return [
            'cero' => [0],
            'diez' => [10],
            'veinte' => [20],
            'cincuenta' => [100],
            'negativo' => [-16],
        ];
    }

    #[DataProvider('numerosInvalidos')]
    public function test_los_numeros_que_no_son_piezas_se_rechazan(int $numero): void
    {
        $this->assertFalse(PiezaFdi::esValido($numero));
    }

    public function test_son_exactamente_32_piezas(): void
    {
        $this->assertCount(32, PiezaFdi::todos());
    }

    /**
     * El primer digito es el cuadrante y el segundo la posicion dentro de el.
     */
    public function test_el_primer_digito_es_el_cuadrante(): void
    {
        $this->assertSame(1, PiezaFdi::cuadrante(16));
        $this->assertSame(2, PiezaFdi::cuadrante(26));
        $this->assertSame(3, PiezaFdi::cuadrante(36));
        $this->assertSame(4, PiezaFdi::cuadrante(46));
    }

    public function test_la_posicion_es_el_segundo_digito(): void
    {
        $this->assertSame(1, PiezaFdi::posicion(11));
        $this->assertSame(6, PiezaFdi::posicion(16));
        $this->assertSame(8, PiezaFdi::posicion(18));
    }

    /**
     * Derecha e izquierda.
     *
     * Es la confusion clasica de la notacion FDI: el cuadrante 1 esta la pieza
     * superior DERECHA del paciente, y el 4 la inferior derecha. El mapa se
     * dibuja al reves que el paciente, y por eso el cuadrante 1 aparece arriba a
     * la izquierda de la pantalla.
     */
    public function test_los_cuadrantes_uno_y_cuatro_son_el_lado_derecho_del_paciente(): void
    {
        $this->assertSame('derecha', PiezaFdi::lado(16));
        $this->assertSame('derecha', PiezaFdi::lado(46));
        $this->assertSame('izquierda', PiezaFdi::lado(26));
        $this->assertSame('izquierda', PiezaFdi::lado(36));
    }

    /**
     * El formato con punto usa el punto de la norma, para que `1.6` no se
     * confunda con la numeracion de Estados Unidos. Se guarda siempre sin
     * punto; el punto queda disponible si el consultorio lo pide.
     */
    public function test_el_formato_con_punto_usa_punto(): void
    {
        $this->assertSame('16', PiezaFdi::formatear(16));
        $this->assertSame('1.6', PiezaFdi::formatear(16, conPunto: true));
        $this->assertSame('4.8', PiezaFdi::formatear(48, conPunto: true));
    }
}