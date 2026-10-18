<?php

declare(strict_types=1);

namespace Tests\Feature\Permisos;

use App\Enums\RolDental;
use App\Enums\SeveridadAlergia;
use App\Models\Alergia;
use App\Models\Cita;
use App\Models\DesbloqueoAlergia;
use App\Models\Paciente;
use App\Models\Profesional;
use App\Services\Agenda\ServicioAgenda;
use DomainException;
use Tests\TestCase;

/**
 * La alergia bloquea la atencion, y solo se levanta con una autorizacion firmada.
 *
 * Esta es la prueba que sostiene el producto. Una alergia activa sin tratar es
 * una de las causas mas comunes de un accidente en el sillon, asi que la regla
 * que hay que comprobar es una sola, y es tajante:
 *
 *   No se agenda un procedimiento sobre una alergia activa hasta que un
 *   profesional con ficha lo autorice por escrito.
 *
 * Ver docs/07.
 */
class AlergiaBloqueaAtencionTest extends TestCase
{
    private Paciente $paciente;

    private Profesional $profesional;

    protected function setUp(): void
    {
        parent::setUp();

        $consultorio = $this->crearConsultorio('alergia-bloqueo');

        $this->crearUsuario($consultorio, RolDental::Recepcion);

        [$usuarioProfesional, $this->profesional] = $this->crearProfesional($consultorio);

        $this->paciente = $this->crearPaciente($consultorio);
    }

    /**
     * Agendar sobre una alergia activa falla, con un mensaje que dice cual.
     */
    public function test_no_se_agenda_sobre_una_alergia_activa(): void
    {
        $consultorio = $this->profesional->consultorio;
        $procedimiento = $this->crearProcedimiento($consultorio, 'Extraccion', 30, 'EXT-001');

        $this->registrarAlergia(SeveridadAlergia::Severa);

        $this->expectException(DomainException::class);

        $this->dentroDe($this->profesional, fn () => app(ServicioAgenda::class)->agendar([
            'paciente' => $this->paciente,
            'profesional' => $this->profesional,
            'procedimiento' => $procedimiento,
            'fecha_inicio' => now()->addDay()->setTime(10, 0),
        ]));
    }

    /**
     * La alergia se escribe en la historia del paciente.
     */
    public function test_el_bloqueo_deja_alergias_registradas_y_no_citas(): void
    {
        $consultorio = $this->profesional->consultorio;
        $procedimiento = $this->crearProcedimiento($consultorio, 'Extraccion', 30, 'EXT-002');

        $this->registrarAlergia(SeveridadAlergia::Anafilactica);

        try {
            $this->dentroDe($this->profesional, fn () => app(ServicioAgenda::class)->agendar([
                'paciente' => $this->paciente,
                'profesional' => $this->profesional,
                'procedimiento' => $procedimiento,
                'fecha_inicio' => now()->addDay()->setTime(10, 0),
            ]));
        } catch (DomainException) {
            // Es lo que se espera. Se sigue para comprobar el estado.
        }

        $this->dentroDe($this->profesional, function (): void {
            $this->assertSame(
                1,
                Alergia::query()->where('paciente_id', $this->paciente->id)->where('activa', true)->count(),
            );

            $this->assertSame(
                0,
                Cita::query()->where('paciente_id', $this->paciente->id)->count(),
                'Se agendo una cita a pesar del bloqueo por alergia.',
            );
        });
    }

    /**
     * Sin alergia, agendar funciona. La prueba anterior no serviría de nada si
     * el fallo fuera por otra cosa.
     */
    public function test_sin_alergia_agendar_si_funciona(): void
    {
        $consultorio = $this->profesional->consultorio;
        $procedimiento = $this->crearProcedimiento($consultorio, 'Limpieza', 30, 'LIM-001');

        $cita = $this->dentroDe($this->profesional, fn (): Cita => app(ServicioAgenda::class)->agendar([
            'paciente' => $this->paciente,
            'profesional' => $this->profesional,
            'procedimiento' => $procedimiento,
            'fecha_inicio' => now()->addDay()->setTime(11, 0),
        ]));

        $this->assertSame($this->paciente->id, $cita->paciente_id);
        $this->assertNull(
            $cita->desbloqueo_alergia_id,
            'Una cita sin alergia no deberia llevar un desbloqueo asociado.',
        );
    }

    /**
     * Una alergia CERRADA no bloquea.
     *
     * Si una alergia cerrada siguiera bloqueando, no habria forma de dejar de
     * tenerla sin borrarla, y borrar es justo lo que no se puede hacer.
     */
    public function test_una_alergia_cerrada_no_bloquea(): void
    {
        $consultorio = $this->profesional->consultorio;
        $procedimiento = $this->crearProcedimiento($consultorio, 'Extraccion', 30, 'EXT-003');

        $alergia = $this->registrarAlergia(SeveridadAlergia::Leve);

        $this->dentroDe($this->profesional, function () use ($alergia): void {
            $alergia->forceFill([
                'activa' => false,
                'fecha_cierre' => now()->toDateString(),
                'motivo_cierre' => 'Prueba de cierre.',
            ])->save();
        });

        $cita = $this->dentroDe($this->profesional, fn (): Cita => app(ServicioAgenda::class)->agendar([
            'paciente' => $this->paciente,
            'profesional' => $this->profesional,
            'procedimiento' => $procedimiento,
            'fecha_inicio' => now()->addDay()->setTime(12, 0),
        ]));

        $this->assertNotNull($cita->id);
    }

    /**
     * Un desbloqueo VIGENTE levanta el bloqueo, y queda anotado en la cita.
     *
     * El `desbloqueo_alergia_id` en la cita es lo que permite que al mirar la
     * agenda se vea que esa cita se agendo sobre una alergia con autorizacion
     * registrada, y no que no habia ninguna.
     */
    public function test_un_desbloqueo_firmado_permite_agendar_y_queda_en_la_cita(): void
    {
        $consultorio = $this->profesional->consultorio;
        $procedimiento = $this->crearProcedimiento($consultorio, 'Extraccion', 30, 'EXT-004');

        $alergia = $this->registrarAlergia(SeveridadAlergia::Anafilactica);

        $desbloqueo = $this->dentroDe($this->profesional, fn (): DesbloqueoAlergia => DesbloqueoAlergia::query()->create([
            'tenant_id' => (string) $this->paciente->tenant_id,
            'paciente_id' => $this->paciente->id,
            'alergia_id' => $alergia->id,
            'profesional_id' => $this->profesional->id,
            'procedimiento_id' => $procedimiento->id,
            'motivo' => 'Testigo informado y con la medicacion de emergencia disponible.',
            'concedido' => now(),
            'vigente_hasta' => now()->addMonth()->toDateString(),
        ]));

        $cita = $this->dentroDe($this->profesional, fn (): Cita => app(ServicioAgenda::class)->agendar([
            'paciente' => $this->paciente,
            'profesional' => $this->profesional,
            'procedimiento' => $procedimiento,
            'fecha_inicio' => now()->addDay()->setTime(9, 0),
        ]));

        $this->assertSame(
            $desbloqueo->id,
            $cita->desbloqueo_alergia_id,
            'La cita no quedo asociada al desbloqueo que la autorizo.',
        );
    }

    /**
     * Un desbloqueo VENCIDO no levanta el bloqueo.
     *
     * Es la otra mitad de la prueba anterior: si la vigencia no se comprobara,
     * un desbloqueo viejo seguiria autorizando atenciones para siempre.
     */
    public function test_un_desbloqueo_vencido_no_permite_agendar(): void
    {
        $consultorio = $this->profesional->consultorio;
        $procedimiento = $this->crearProcedimiento($consultorio, 'Extraccion', 30, 'EXT-005');

        $alergia = $this->registrarAlergia(SeveridadAlergia::Severa);

        $this->dentroDe($this->profesional, function () use ($alergia, $procedimiento): void {
            DesbloqueoAlergia::query()->create([
                'tenant_id' => (string) $this->paciente->tenant_id,
                'paciente_id' => $this->paciente->id,
                'alergia_id' => $alergia->id,
                'profesional_id' => $this->profesional->id,
                'procedimiento_id' => $procedimiento->id,
                'motivo' => 'Autorizacion de una atencion anterior, ya terminada.',
                'concedido' => now()->subMonths(3),
                'vigente_hasta' => now()->subMonths(2)->toDateString(),
            ]);
        });

        $this->expectException(DomainException::class);

        $this->dentroDe($this->profesional, fn () => app(ServicioAgenda::class)->agendar([
            'paciente' => $this->paciente,
            'profesional' => $this->profesional,
            'procedimiento' => $procedimiento,
            'fecha_inicio' => now()->addDay()->setTime(10, 0),
        ]));
    }

    /**
     * Recepcion agenda igual que el profesional: el bloqueo es del paciente, no
     * del rol de quien agenda.
     */
    public function test_a_recepcion_tambien_le_bloquea_la_alergia(): void
    {
        $consultorio = $this->profesional->consultorio;
        $recepcion = $this->crearUsuario($consultorio, RolDental::Recepcion);
        $procedimiento = $this->crearProcedimiento($consultorio, 'Extraccion', 30, 'EXT-006');

        $this->registrarAlergia(SeveridadAlergia::Severa);

        $this->expectException(DomainException::class);

        $this->dentroDe($recepcion, fn () => app(ServicioAgenda::class)->agendar([
            'paciente' => $this->paciente,
            'profesional' => $this->profesional,
            'procedimiento' => $procedimiento,
            'fecha_inicio' => now()->addDay()->setTime(15, 0),
        ]));
    }

    private function registrarAlergia(SeveridadAlergia $severidad): Alergia
    {
        return $this->dentroDe($this->profesional, fn (): Alergia => Alergia::query()->create([
            'tenant_id' => (string) $this->paciente->tenant_id,
            'paciente_id' => $this->paciente->id,
            'profesional_id' => $this->profesional->id,
            'sustancia' => 'Latex',
            'reaccion' => 'Reaccion anafilactica documentada en dos atenciones anteriores',
            'severidad' => $severidad->value,
            'activa' => true,
            'fecha_registro' => now()->subMonth()->toDateString(),
        ]));
    }
}