<?php

declare(strict_types=1);

use App\Http\Controllers\Administracion\InsumoController;
use App\Http\Controllers\Administracion\ProcedimientoController;
use App\Http\Controllers\Administracion\ProfesionalController;
use App\Http\Controllers\Administracion\TarifaController;
use App\Http\Controllers\Administracion\UsuarioController;
use App\Http\Controllers\Agenda\AgendaController;
use App\Http\Controllers\Agenda\CitaController;
use App\Http\Controllers\Alergias\AlergiaController;
use App\Http\Controllers\Alergias\CondicionController;
use App\Http\Controllers\Alergias\DesbloqueoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Odontograma\CronologiaController;
use App\Http\Controllers\Odontograma\IntervencionController;
use App\Http\Controllers\Odontograma\OdontogramaController;
use App\Http\Controllers\Pacientes\PacienteController;
use App\Http\Controllers\Panel\AuditoriaController;
use App\Http\Controllers\Panel\PanelController;
use App\Http\Controllers\Planes\PlanController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Rutas de la API de ClinicaDental
|-------------------------------------------------------------------------------
|
| Todas cuelgan del grupo `web` de Laravel (ver bootstrap/app.php) y del prefijo
| `/api/v1`, que se aplica aqui y no en el esqueleto: queda a la vista que TODA
| la superficie de la API esta versionada en una sola linea.
|
| Estas rutas las consume el BFF de Nuxt (web/server/api), que reenvia las
| cookies del navegador. Ver docs/01 y docs/09.
|
| EL CONTROL FINO NO ESTA ACA
| --------------------------
| Las rutas declaran QUE recurso se toca y CON QUE MIDDLEWARE. Quien puede
| tocarlo lo decide `App\Policies`. El orden importa y es el siguiente:
|
|   1. `auth`       confirma la sesion
|   2. `admin`      corta las rutas de administracion para quien no administra
|   3. `odontograma.auditado`  anota el intento, permitido o denegado
|   4. Policy       permiso fino, con 404 si rechaza
|
| Un rechazo de Policy responde 404 y no 403: un 403 confirmaria que el recurso
| existe. Ver bootstrap/app.php y docs/03.
|
*/
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {

    /*
    |---------------------------------------------------------------------------
    | Autenticacion (OIDC contra Authentik)
    |---------------------------------------------------------------------------
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('login', [AuthController::class, 'login'])->name('login');
        Route::get('callback', [AuthController::class, 'callback'])->name('callback');

        Route::middleware('auth')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('usuario', [AuthController::class, 'usuario'])->name('usuario');
        });
    });

    Route::middleware('auth')->group(function (): void {

        /*
        |-----------------------------------------------------------------------
        | Panel
        |-----------------------------------------------------------------------
        | Lo primero que se ve. Solo cuenta cosas: no abre historia clinica, y por
        | eso lo ven los tres roles. Ver PanelController.
        */
        Route::get('panel', PanelController::class)->name('panel');

        /*
        |-----------------------------------------------------------------------
        | Agenda
        |-----------------------------------------------------------------------
        | Recepcion entra aqui: agenda, ve la agenda y ve los riesgos. Datos de
        | agenda, no de salud, asi que NO pasa por la auditoria de odontograma.
        */
        Route::get('agenda', [AgendaController::class, 'show'])->name('agenda');
        Route::get('agenda/estados', [AgendaController::class, 'estados'])->name('agenda.estados');
        Route::get('agenda/riesgos/{paciente}', [AgendaController::class, 'riesgos'])->name('agenda.riesgos');

        Route::apiResource('citas', CitaController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy'])
            ->parameters(['citas' => 'cita'])
            ->names('citas');

        // Anular y registrar el desenlace son rutas aparte de la edicion porque
        // son hechos distintos: anular es una decision de agenda y el desenlace
        // es un hecho clinico que queda en la historia del paciente.
        Route::post('citas/{cita}/desenlace', [CitaController::class, 'desenlace'])
            ->name('citas.desenlace');

        /*
        |-----------------------------------------------------------------------
        | Pacientes: ficha de IDENTIFICACION
        |-----------------------------------------------------------------------
        | Nombre, RUT, contacto, prevision. Datos personales, no de salud, asi
        | que los tres roles entran. El historial odontologico de estos mismos
        | pacientes esta mas abajo, en rutas auditadas. Ver PacientePolicy.
        */
        Route::apiResource('pacientes', PacienteController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy'])
            ->parameters(['pacientes' => 'paciente'])
            ->names('pacientes');

        /*
        |-----------------------------------------------------------------------
        | ALERGIAS Y CONDICIONES
        |-----------------------------------------------------------------------
        | Las ven los tres roles, y es una decision consciente: si recepcion no
        | las viera al agendar, el sistema seria PEOR que una hoja de papel.
        | Registrarlas y cerrarlas es del profesional. Ver AlergiaPolicy.
        |
        | NO pasan por `odontograma.auditado`: no son el historial odontologico
        | del paciente, son la lista de lo que NO se le puede hacer. Esa
        | distincion es la que sostiene el diseno. Ver docs/03.
        */
        Route::apiResource('pacientes.alergias', AlergiaController::class)
            ->only(['index', 'store', 'update'])
            ->parameters(['pacientes' => 'paciente', 'alergias' => 'alergia'])
            ->names('pacientes.alergias');

        Route::get('escala-alergias', [AlergiaController::class, 'escala'])->name('escala.alergias');

        Route::post('pacientes/alergias/{alergia}/cerrar', [AlergiaController::class, 'cerrar'])
            ->name('pacientes.alergias.cerrar');

        Route::apiResource('pacientes.condiciones', CondicionController::class)
            ->only(['index', 'store', 'update'])
            ->parameters(['pacientes' => 'paciente', 'condiciones' => 'condicion'])
            ->names('pacientes.condiciones');

        Route::post('pacientes/condiciones/{condicion}/cerrar', [CondicionController::class, 'cerrar'])
            ->name('pacientes.condiciones.cerrar');

        /*
        |-----------------------------------------------------------------------
        | DESBLOQUEOS: la via por la que se hace igual una atencion prohibida
        |-----------------------------------------------------------------------
        | Solo un profesional con ficha de profesional concede. Sin motivo escrito
        | no hay desbloqueo. No se editan ni se borran: se cierran. Ver
        | DesbloqueoPolicy y docs/07.
        */
        Route::apiResource('pacientes.desbloqueos', DesbloqueoController::class)
            ->only(['index', 'store', 'destroy'])
            ->parameters(['pacientes' => 'paciente', 'desbloqueos' => 'desbloqueo'])
            ->names('pacientes.desbloqueos');

        // Comprobacion previa: por que no se puede agendar y que habria que
        // desbloquear. La pantalla la llama ANTES de intentar agendar, para que
        // el motivo del bloqueo no sea un error seco.
        Route::get('pacientes/{paciente}/desbloqueos/comprobar', [DesbloqueoController::class, 'comprobar'])
            ->name('pacientes.desbloqueos.comprobar');

        /*
        |-----------------------------------------------------------------------
        | HISTORIAL ODONTOLOGICO: TODO ESTO VA AUDITADO
        |-----------------------------------------------------------------------
        | A partir de aca se abre informacion de salud. Cada intento queda en
        | `accesos_odontograma`, permitido o denegado, con quien, cuando y desde
        | donde. El middleware corta con 404 antes de que la accion llegue al
        | controlador, y por eso el intento denegado tambien queda anotado.
        |
        | Ver App\Http\Middleware\AuditarOdontograma y docs/07.
        */
        Route::middleware('odontograma.auditado:ver_estado_actual')->group(function (): void {

            // El mapa: estado actual de las 32 piezas. Los tres roles.
            Route::get('pacientes/{paciente}/odontograma', [OdontogramaController::class, 'show'])
                ->name('pacientes.odontograma');

            Route::post('pacientes/{paciente}/odontograma', [OdontogramaController::class, 'store'])
                ->name('pacientes.odontograma.store');
        });

        Route::middleware('odontograma.auditado:ver_cronologia')->group(function (): void {

            // La cronologia de una pieza. Solo profesional y administrador.
            Route::get('pacientes/{paciente}/cronologia/{numero_fdi}', [CronologiaController::class, 'show'])
                ->where('numero_fdi', '[0-9]{2}')
                ->name('pacientes.cronologia');

            // La cronologia completa, agrupada por fecha.
            Route::get('pacientes/{paciente}/historial/{odontograma}', [CronologiaController::class, 'completa'])
                ->name('pacientes.historial');
        });

        Route::middleware('odontograma.auditado:registrar_intervencion')->group(function (): void {

            // Registrar una intervencion: la unica via para cambiar el estado de
            // una pieza. Exige rol profesional CON ficha de profesional.
            Route::post('pacientes/{paciente}/intervenciones', [IntervencionController::class, 'store'])
                ->name('pacientes.intervenciones.store');

            // Transiciones validas desde el estado actual de una pieza. Es una
            // ayuda para la interfaz; el servidor las vuelve a comprobar.
            Route::get('pacientes/{paciente}/odontograma/{odontograma}/transiciones/{numero_fdi}', [IntervencionController::class, 'transiciones'])
                ->where('numero_fdi', '[0-9]{2}')
                ->name('pacientes.intervenciones.transiciones');
        });

        Route::middleware('odontograma.auditado:ver_plan')->group(function (): void {
            Route::get('pacientes/{paciente}/planes', [PlanController::class, 'index'])
                ->name('pacientes.planes');
            Route::get('planes/{plan}', [PlanController::class, 'show'])->name('planes.show');
        });

        Route::middleware('odontograma.auditado:editar_plan')->group(function (): void {
            Route::post('pacientes/{paciente}/planes', [PlanController::class, 'store'])
                ->name('pacientes.planes.store');
            Route::patch('planes/{plan}', [PlanController::class, 'update'])->name('planes.update');
            Route::post('planes/{plan}/estado', [PlanController::class, 'cambiarEstado'])
                ->name('planes.estado');
            Route::post('planes/{plan}/etapas', [PlanController::class, 'agregarEtapa'])
                ->name('planes.etapas.store');
            Route::patch('etapas-plan/{etapa}/estado', [PlanController::class, 'estadoEtapa'])
                ->name('etapas-plan.estado');
        });

        /*
        |-----------------------------------------------------------------------
        | ADMINISTRACION DEL CONSULTORIO
        |-----------------------------------------------------------------------
        | Las cinco rutas cuelgan del middleware `admin`, que corta para quien no
        | es administrador ANTES de llegar a la Policy. Son dos capas: el
        | middleware es la puerta, la Policy es la regla. Ver bootstrap/app.php.
        |
        | La bitacora va aparte porque tiene su propia razon: el administrador
        | que administra el consultorio no lee historiales clinicos, pero si
        | puede leer el CUENTO de quien los abrio.
        */
        Route::middleware('admin')->group(function (): void {

            Route::get('administracion/profesionales/para-agenda', [ProfesionalController::class, 'paraAgenda'])
                ->name('administracion.profesionales.para-agenda');

            Route::apiResource('administracion/profesionales', ProfesionalController::class)
                ->only(['index', 'store', 'show', 'update'])
                ->parameters(['administracion/profesionales' => 'profesional'])
                ->names('administracion.profesionales');

            Route::apiResource('administracion/procedimientos', ProcedimientoController::class)
                ->only(['index', 'store', 'show', 'update'])
                ->parameters(['administracion/procedimientos' => 'procedimiento'])
                ->names('administracion.procedimientos');

            Route::apiResource('administracion/insumos', InsumoController::class)
                ->only(['index', 'store', 'update'])
                ->parameters(['administracion/insumos' => 'insumo'])
                ->names('administracion.insumos');

            Route::post('administracion/insumos/{insumo}/stock', [InsumoController::class, 'ajustarStock'])
                ->name('administracion.insumos.stock');

            Route::apiResource('administracion/tarifas', TarifaController::class)
                ->only(['index', 'store', 'update'])
                ->parameters(['administracion/tarifas' => 'tarifa'])
                ->names('administracion.tarifas');

            Route::get('administracion/usuarios', [UsuarioController::class, 'index'])
                ->name('administracion.usuarios');

            Route::post('administracion/usuarios/{usuario}/profesional', [UsuarioController::class, 'vincularProfesional'])
                ->name('administracion.usuarios.profesional');

            Route::post('administracion/usuarios/{usuario}/desactivar', [UsuarioController::class, 'desactivar'])
                ->name('administracion.usuarios.desactivar');

            // Bitacora de accesos al historial odontologico.
            Route::get('administracion/auditoria', AuditoriaController::class)
                ->name('administracion.auditoria');
        });
    });
});