<?php

declare(strict_types=1);

namespace Tests\Feature\Permisos;

use App\Enums\AccionOdontograma;
use App\Enums\ResultadoAcceso;
use App\Enums\RolDental;
use App\Models\AccesoOdontograma;
use App\Models\Paciente;
use App\Services\Permisos\CapacidadesDeUsuario;
use Tests\TestCase;

/**
 * El historial odontologico no lo abre todo el mundo, y se sabe quien lo abrio.
 *
 * DOS COSAS DISTINTAS, Y LAS DOS IMPORTAN
 * ---------------------------------------
 *   1. QUE PUEDA ABRIRSE. La cronologia de las piezas es informacion de salud.
 *      Recepcion no la ve. El administrador tampoco, salvo que ademas tenga
 *      ficha de profesional.
 *   2. QUE QUEDE ANOTADO. Cada intento, permitido o denegado, va a
 *      `accesos_odontograma`. Sin el denegado anotado, no hay forma de saber que
 *      alguien estuvo probando el sistema con el rol equivocado.
 *
 * Y el rechazo es 404, no 403: un 403 confirmaria que el recurso existe. Ver
 * docs/03 y docs/07.
 */
class HistorialOdontologicoYBitacoraTest extends TestCase
{
    public function test_recepcion_ve_el_mapa_pero_no_la_cronologia(): void
    {
        $consultorio = $this->crearConsultorio('roles-cronologia');
        $recepcion = $this->crearUsuario($consultorio, RolDental::Recepcion);
        [$profesionalUsuario] = $this->crearProfesional($consultorio);
        $paciente = $this->crearPaciente($consultorio);

        $capacidades = new CapacidadesDeUsuario($recepcion);

        $this->assertTrue(
            $capacidades->puedeVerEstadoActual(),
            'Recepcion debe ver el mapa: necesita contexto para agendar con criterio.',
        );

        $this->assertFalse(
            $capacidades->puedeVerCronologia(),
            'Recepcion no debe ver la cronologia de las piezas.',
        );
    }

    public function test_recepcion_no_abre_la_cronologia_por_la_api(): void
    {
        $consultorio = $this->crearConsultorio('api-cronologia');
        $recepcion = $this->crearUsuario($consultorio, RolDental::Recepcion);
        [$profesionalUsuario] = $this->crearProfesional($consultorio);
        $paciente = $this->crearPaciente($consultorio);

        $this->crearOdontograma($profesionalUsuario, $paciente);

        $respuesta = $this->actingAs($recepcion)
            ->getJson('/api/v1/pacientes/' . $paciente->id . '/cronologia/16');

        $respuesta->assertNotFound();
    }

    public function test_recepcion_si_abre_el_mapa_por_la_api(): void
    {
        $consultorio = $this->crearConsultorio('api-mapa');
        $recepcion = $this->crearUsuario($consultorio, RolDental::Recepcion);

        $paciente = $this->crearPaciente($consultorio);

        $respuesta = $this->actingAs($recepcion)
            ->getJson('/api/v1/pacientes/' . $paciente->id . '/odontograma');

        $respuesta->assertOk();
        $respuesta->assertJsonCount(32, 'piezas');
    }

    /**
     * El mapa de recepcion no trae las notas de las piezas.
     *
     * Las notas son el relato del profesional, no el estado. Van en `null`, no
     * ausentes, para que la interfaz no adivine.
     */
    public function test_el_mapa_de_recepcion_no_trae_notas(): void
    {
        $consultorio = $this->crearConsultorio('notas-mapa');
        $recepcion = $this->crearUsuario($consultorio, RolDental::Recepcion);

        $paciente = $this->crearPaciente($consultorio);

        $respuesta = $this->actingAs($recepcion)
            ->getJson('/api/v1/pacientes/' . $paciente->id . '/odontograma');

        $respuesta->assertOk();
        $respuesta->assertJsonPath('incluye_notas', false);

        $conNotas = array_filter(
            $respuesta->json('piezas'),
            static fn (array $pieza): bool => $pieza['notas'] !== null,
        );

        $this->assertCount(0, $conNotas, 'Recepcion recibio notas de pieza en el mapa.');
    }

    /**
     * El intento denegado queda anotado.
     *
     * Y queda con resultado `denegado`, no simplemente ausente. Es la unica forma
     * de detectar que alguien esta probando el sistema con el rol equivocado.
     */
    public function test_el_intento_denegado_queda_en_la_bitacora(): void
    {
        $consultorio = $this->crearConsultorio('bitacora-denegado');
        $recepcion = $this->crearUsuario($consultorio, RolDental::Recepcion);
        [$profesionalUsuario] = $this->crearProfesional($consultorio);
        $paciente = $this->crearPaciente($consultorio);

        $this->crearOdontograma($profesionalUsuario, $paciente);

        $this->actingAs($recepcion)
            ->getJson('/api/v1/pacientes/' . $paciente->id . '/cronologia/16')
            ->assertNotFound();

        $registro = AccesoOdontograma::query()
            ->where('paciente_id', $paciente->id)
            ->orderByDesc('momento')
            ->first();

        $this->assertNotNull($registro, 'El intento denegado no quedo en la bitacora.');

        $this->assertSame(ResultadoAcceso::Denegado->value, $registro->resultado);
        $this->assertSame(AccionOdontograma::VerCronologia->value, $registro->accion);
        $this->assertSame((string) $recepcion->id, (string) $registro->usuario_id);
        $this->assertSame(404, $registro->codigo_respuesta);
    }

    /**
     * El acceso permitido tambien queda anotado.
     *
     * "Solo los denegados" no sirve: la pregunta que se le hace al sistema es
     * tambien "quien miro la ficha de este paciente".
     */
    public function test_el_acceso_permitido_tambien_queda_en_la_bitacora(): void
    {
        $consultorio = $this->crearConsultorio('bitacora-permitido');
        [$profesionalUsuario] = $this->crearProfesional($consultorio);
        $paciente = $this->crearPaciente($consultorio);

        $this->crearOdontograma($profesionalUsuario, $paciente);

        $this->actingAs($profesionalUsuario)
            ->getJson('/api/v1/pacientes/' . $paciente->id . '/cronologia/16')
            ->assertOk();

        $registro = AccesoOdontograma::query()
            ->where('paciente_id', $paciente->id)
            ->orderByDesc('momento')
            ->first();

        $this->assertNotNull($registro, 'El acceso permitido no quedo en la bitacora.');
        $this->assertSame(ResultadoAcceso::Permitido->value, $registro->resultado);
    }

    /**
     * Un profesional sin ficha no puede registrar intervenciones.
     *
     * El rol alcanza, pero la ficha de profesional no. La intervencion quedaria
     * sin tratante, y un hecho clinico sin tratante no se puede sostener dentro
     * de una auditoria.
     */
    public function test_un_profesional_sin_ficha_no_registra_intervenciones(): void
    {
        $consultorio = $this->crearConsultorio('sin-ficha');
        $sinFicha = $this->crearUsuario($consultorio, RolDental::Profesional);
        $paciente = $this->crearPaciente($consultorio);

        $capacidades = new CapacidadesDeUsuario($sinFicha);

        $this->assertTrue($capacidades->puedeVerCronologia(), 'Deberia poder VER la cronologia.');
        $this->assertFalse(
            $capacidades->puedeRegistrarIntervencion(),
            'Sin ficha de profesional no deberia poder registrar intervenciones.',
        );
    }

    /**
     * Un usuario desactivado no opera.
     *
     * `ResolverTenant` corta antes de tocar la base, con 403 y un mensaje claro.
     */
    public function test_un_usuario_desactivado_no_opera(): void
    {
        $consultorio = $this->crearConsultorio('desactivado');
        $usuario = $this->crearUsuario($consultorio, RolDental::Recepcion);
        $paciente = $this->crearPaciente($consultorio);

        $this->dentroDe($usuario, function () use ($usuario): void {
            $usuario->forceFill(['activo' => false])->save();
        });

        $respuesta = $this->actingAs($usuario)
            ->getJson('/api/v1/pacientes/' . $paciente->id);

        $respuesta->assertForbidden();
    }

    /**
     * El administrador ve la cronologia pero no puede escribir en ella.
     *
     * La direccion del consultorio no es un cargo clinico. Ver
     * PlanTratamientoPolicy y docs/03.
     */
    public function test_el_administrador_ve_la_cronologia_pero_no_registra_intervenciones(): void
    {
        $consultorio = $this->crearConsultorio('administrador');
        $administrador = $this->crearUsuario($consultorio, RolDental::Administrador);

        $capacidades = new CapacidadesDeUsuario($administrador);

        $this->assertTrue($capacidades->puedeVerCronologia());
        $this->assertFalse($capacidades->puedeRegistrarIntervencion());
        $this->assertFalse($capacidades->puedeDesbloquearAlergias());
        $this->assertTrue($capacidades->puedeVerAuditoria());
    }

    /**
     * La bitacora no la ve quien solo ve pacientes.
     *
     * La bitacora dice quien abrio el historial de quien: es informacion sobre
     * las personas de la institucion tanto como sobre los pacientes. Ver
     * AccesoOdontogramaPolicy.
     */
    public function test_la_bitacora_no_la_ve_recepcion(): void
    {
        $consultorio = $this->crearConsultorio('bitacora-permiso');
        $recepcion = $this->crearUsuario($consultorio, RolDental::Recepcion);

        $this->actingAs($recepcion)
            ->getJson('/api/v1/administracion/auditoria')
            ->assertNotFound();
    }

    public function test_el_administrador_si_abre_la_bitacora(): void
    {
        $consultorio = $this->crearConsultorio('bitacora-admin');
        $administrador = $this->crearUsuario($consultorio, RolDental::Administrador);

        $this->actingAs($administrador)
            ->getJson('/api/v1/administracion/auditoria')
            ->assertOk()
            ->assertJsonPath('aviso_automatico_implementado', false);
    }

    /**
     * La bitacora es del consultorio, no de la instalacion.
     */
    public function test_la_bitacora_no_mezcla_consultorios(): void
    {
        $consultorioA = $this->crearConsultorio('bitacora-a');
        $consultorioB = $this->crearConsultorio('bitacora-b');

        $administradorA = $this->crearUsuario($consultorioA, RolDental::Administrador);
        [$profesionalB] = $this->crearProfesional($consultorioB);
        $pacienteB = $this->crearPaciente($consultorioB);

        $this->crearOdontograma($profesionalB, $pacienteB);

        $this->actingAs($profesionalB)
            ->getJson('/api/v1/pacientes/' . $pacienteB->id . '/odontograma')
            ->assertOk();

        $respuesta = $this->actingAs($administradorA)
            ->getJson('/api/v1/administracion/auditoria');

        $respuesta->assertOk();
        $this->assertSame(0, $respuesta->json('total'), 'La bitacora de A mostro accesos de B.');
    }

    /**
     * El registro guarda con quien, cuando y desde donde.
     *
     * Sin la direccion IP, un registro de acceso no sirve para nada: un
     * profesional que vio una ficha desde su casa y otro que la vio desde el
     * consultorio dejan el mismo registro.
     *
     * NOTA SOBRE LA INMUTABILIDAD
     * ---------------------------
     * NO se prueba aca que el registro no se pueda modificar, porque el modelo
     * `AccesoOdontograma` no impone esa restriccion. Es una laguna real, anotada
     * en docs/07 ("Lo que NO esta hecho") y en docs/10. Se declara aqui para que
     * no se pierda de vista: mientras exista, la inmutabilidad depende de que
     * nadie escriba en la tabla, y no del sistema.
     */
    public function test_el_registro_guarda_quien_cuando_y_desde_donde(): void
    {
        $consultorio = $this->crearConsultorio('bitacora-contexto');
        [$profesionalUsuario] = $this->crearProfesional($consultorio);
        $paciente = $this->crearPaciente($consultorio);

        $this->crearOdontograma($profesionalUsuario, $paciente);

        $this->actingAs($profesionalUsuario)
            ->getJson('/api/v1/pacientes/' . $paciente->id . '/odontograma')
            ->assertOk();

        $registro = AccesoOdontograma::query()
            ->where('paciente_id', $paciente->id)
            ->orderByDesc('momento')
            ->firstOrFail();

        $this->assertSame((string) $profesionalUsuario->id, (string) $registro->usuario_id);
        $this->assertSame('GET', $registro->metodo_http);
        $this->assertSame(200, $registro->codigo_respuesta);
        $this->assertStringContainsString('odontograma', (string) $registro->ruta);
        $this->assertNotNull($registro->nombre_ruta);
        $this->assertNotNull($registro->momento);
    }
}