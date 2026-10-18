<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Enums\RolDental;
use App\Models\Odontograma;
use App\Models\Paciente;
use App\Models\Profesional;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Un consultorio no puede ver los datos de otro.
 *
 * Lo que se comprueba aca es la PRIMERA capa del aislamiento, que vive en la
 * base de datos y no en PHP. Que la aplicacion agregue un `where tenant_id` es
 * una cortesia: si alguien lo olvida, la base lo impide.
 *
 * Ver docs/03.
 */
class AislamientoEntreConsultoriosTest extends TestCase
{
    public function test_un_consultorio_no_ve_pacientes_de_otro(): void
    {
        $consultorioA = $this->crearConsultorio('aislamiento-a');
        $consultorioB = $this->crearConsultorio('aislamiento-b');

        $pacienteB = $this->crearPaciente($consultorioB);

        $vistos = $this->dentroDe($consultorioA, fn (): int => Paciente::query()
            ->whereKey($pacienteB->id)
            ->count());

        $this->assertSame(
            0,
            $vistos,
            'El consultorio A alcanzo a ver un paciente del consultorio B.',
        );
    }

    public function test_un_consultorio_no_ve_el_historial_odontologico_de_otro(): void
    {
        $consultorioA = $this->crearConsultorio('historial-a');
        $consultorioB = $this->crearConsultorio('historial-b');

        $usuarioB = $this->crearUsuario($consultorioB, RolDental::Profesional);
        $pacienteB = $this->crearPaciente($consultorioB);

        $this->crearOdontograma($usuarioB, $pacienteB);

        $vistosEnA = $this->dentroDe($consultorioA, fn (): int => Odontograma::query()
            ->where('paciente_id', $pacienteB->id)
            ->count());

        $vistosEnB = $this->dentroDe($consultorioB, fn (): int => Odontograma::query()
            ->where('paciente_id', $pacienteB->id)
            ->count());

        $this->assertSame(0, $vistosEnA, 'El consultorio A vio un odontograma del consultorio B.');
        $this->assertSame(1, $vistosEnB, 'El consultorio B no ve su propio odontograma.');
    }

    public function test_un_consultorio_no_ve_los_profesionales_de_otro(): void
    {
        $consultorioA = $this->crearConsultorio('profesionales-a');
        $consultorioB = $this->crearConsultorio('profesionales-b');

        [, $profesionalB] = $this->crearProfesional($consultorioB);

        $vistos = $this->dentroDe($consultorioA, fn (): int => Profesional::query()
            ->whereKey($profesionalB->id)
            ->count());

        $this->assertSame(0, $vistos);
    }

    /**
     * La comprobacion que sostiene todo lo demas.
     *
     * Sin `app.tenant_id` fijado, `public.app_tenant_id()` tiene que LANZAR
     * EXCEPCION. Si devolviera `NULL`, la consulta daria cero filas y el fallo
     * seria silencioso: el sistema pareceria funcionar, solo que no mostraria
     * nada, y nadie se enteraria hasta que faltara un dato.
     */
    public function test_sin_contexto_la_consulta_falla_y_no_devuelve_filas(): void
    {
        $this->expectException(Throwable::class);

        Paciente::query()->count();
    }

    /**
     * `WITH CHECK`: no se puede escribir una fila declarando otro consultorio.
     *
     * Sin esta clausula, se podria crear una fila que pertenece a otro
     * consultorio, y esa fila despues seria invisible para los dos: para quien
     * la escribio, porque no es su tenant, y para el dueno, porque la RLS no lo
     * deja leer. Quedaria en la base sin que nadie la pudiera ver nunca.
     */
    public function test_no_se_puede_escribir_una_fila_de_otro_consultorio(): void
    {
        $consultorioA = $this->crearConsultorio('escritura-a');
        $consultorioB = $this->crearConsultorio('escritura-b');

        $rechazada = false;

        try {
            $this->dentroDe($consultorioA, function () use ($consultorioB): void {
                Paciente::query()->create([
                    'tenant_id' => (string) $consultorioB->id,
                    'nombres' => 'Intruso',
                    'apellidos' => 'De Otro Consultorio',
                    'sexo' => 'no_informado',
                    'activo' => true,
                ]);
            });
        } catch (Throwable) {
            $rechazada = true;
        }

        $this->assertTrue(
            $rechazada,
            'Se pudo escribir un paciente declarando el consultorio de otro. Falta WITH CHECK en la politica.',
        );
    }

    /**
     * Por la API, un paciente de otro consultorio responde 404.
     *
     * 404 y no 403: un 403 confirmaria que el recurso existe.
     */
    public function test_por_la_api_un_paciente_de_otro_consultorio_responde_404(): void
    {
        $consultorioA = $this->crearConsultorio('api-a');
        $consultorioB = $this->crearConsultorio('api-b');

        $usuarioA = $this->crearUsuario($consultorioA, RolDental::Recepcion);
        $pacienteB = $this->crearPaciente($consultorioB);

        $respuesta = $this->actingAs($usuarioA)
            ->getJson('/api/v1/pacientes/' . $pacienteB->id);

        $respuesta->assertNotFound();
        $respuesta->assertDontSee($pacienteB->nombres, false);
    }

    /**
     * El contexto tiene alcance de TRANSACCION: no sobrevive a la confirmacion.
     *
     * Si se fijara con un `SET` normal (ambito de sesion), el valor quedaria en
     * la conexion. En el worker de colas, que reutiliza la misma conexion para
     * trabajos de consultorios distintos, una tarea leeria datos de otra
     * clinica. Es exactamente el fallo que este diseno evita.
     */
    public function test_el_contexto_desaparece_al_terminar_la_transaccion(): void
    {
        $consultorio = $this->crearConsultorio('alcance');
        $contexto = app(ContextoTenant::class);

        $this->dentroDe($consultorio, function () use ($contexto): void {
            $this->assertNotNull(
                $contexto->consultorioIdEnConexion(),
                'Dentro de la transaccion el contexto deberia estar fijado.',
            );
        });

        $this->assertNull(
            $contexto->consultorioIdEnConexion(),
            'El contexto sobrevivio a la transaccion. El alcance deberia ser de transaccion.',
        );
    }

    /**
     * La funcion de contexto devuelve el consultorio fijado.
     */
    public function test_la_funcion_de_contexto_devuelve_el_consultorio_fijado(): void
    {
        $consultorio = $this->crearConsultorio('funcion-contexto');

        $obtenido = $this->dentroDe($consultorio, fn (): string => (string) DB::selectOne(
            'SELECT public.app_tenant_id() AS valor',
        )->valor);

        $this->assertSame((string) $consultorio->id, $obtenido);
    }
}