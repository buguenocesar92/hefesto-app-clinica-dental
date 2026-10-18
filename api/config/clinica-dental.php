<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Reglas de ClinicaDental
|------------------------------------------------------------------------------
|
| Lo que la aplicacion necesita saber del consultorio y que no cabe en el
| codigo. Cada clave esta justificada al lado. Ver docs/05.
|
*/

$enteroONulo = static function (?string $valor): ?int {
    if ($valor === null) {
        return null;
    }

    $valor = trim($valor);

    return $valor === '' ? null : (int) $valor;
};

return [

    /*
    |----------------------------------------------------------------------
    | Agenda
    |----------------------------------------------------------------------
    |
    | La duracion de una cita NO viene del cliente: sale de la duracion del
    | procedimiento (App\Services\Agenda\ServicioAgenda). Estos dos limites son
    | la red de seguridad para cuando el catalogo tiene un dato raro.
    |
    */
    'agenda' => [
        'duracion_maxima_minutos' => (int) env('CLINICA_DENTAL_DURACION_MAXIMA_MINUTOS', 240),
        'anticipacion_maxima_dias' => (int) env('CLINICA_DENTAL_ANTICIPACION_MAXIMA_DIAS', 180),
        'margen_entre_citas_minutos' => (int) env('CLINICA_DENTAL_MARGEN_ENTRE_CITAS', 0),
    ],

    /*
    |----------------------------------------------------------------------
    | Alergias
    |----------------------------------------------------------------------
    |
    | UN BLOQUEO DE ALERGIA NUNCA SE CALCULA CON UN NUMERO DE DIAS FIJO.
    | Un desbloqueo sin fecha de vencimiento (`vigente_hasta` en null) vale
    | hasta que un profesional lo cierre a mano, y esa es la conducta por
    | omision. Si un consultorio quiere un limite, se configura aqui y el
    | servicio lo aplica al crear el desbloqueo.
    |
    | Ver docs/07: el plazo de vigencia de un desbloqueo es una decision
    | clinica del consultorio, no un dato que pueda inventar el software.
    |
    */
    'alergias' => [
        // Dias de vigencia por omision de un desbloqueo. En null, no vence.
        'vigencia_desbloqueo_dias' => $enteroONulo(env('CLINICA_DENTAL_VIGENCIA_DESBLOQUEO_DIAS')),
        // Los casos graves exigen un motivo mas largo. Es una ayuda para que
        // el profesional escriba lo que lo sustenta, no un criterio clinico.
        'motivo_minimo_desbloqueo' => 15,
    ],

    /*
    |----------------------------------------------------------------------
    | CONSERVACION: DECISION DEL CLIENTE, NO DEL SOFTWARE
    |----------------------------------------------------------------------
    |
    | ESTA ES LA PARTE MAS DELICADA DE ESTA CONFIGURACION, LEELA ANTES DE
    | CAMBIAR NADA.
    |
    | La Ley 19.628 obliga a que la conservacion de datos personales tenga una
    | finalidad declarada, pero NO fija un plazo unico para las fichas
    | clinicas, y su articulado no se pudo verificar en el entorno donde se
    | escribio esta aplicacion. Ver docs/07.
    |
    | Por eso el valor por omision es `null`, y `null` significa:
    |
    |     "no hay plazo configurado".
    |
    | Que es exactamente lo que dice el campo, y no un numero inventado con
    | buena intencion. Si un consultorio carga fichas de salud y su attorney
    | revisa el caso, el plazo se configura por instalacion y queda escrito
    | en el documento de tratamiento de datos de ESE consultorio.
    |
    | La consequence de dejar `null` es deliberada: la aplicacion NO purga
    | nada por su cuenta. Borrar historia clinica porque paso un numero
    | inventado seria peor que no borrar nunca.
    |
    */
    'conservacion' => [
        // Dias que se conservan los accesos al odontograma. En null, no se
        // purga nada y queda como decision pendiente del cliente.
        'bitacora_accesos_dias' => $enteroONulo(env('CLINICA_DENTAL_RETENCION_BITACORA_DIAS')),

        // Dias que se conserva una intervencion antes de que el sistema
        // proponga purgarla. En null, no se propone nada.
        'intervenciones_dias' => $enteroONulo(env('CLINICA_DENTAL_RETENCION_INTERVENCIONES_DIAS')),

        // Si la aplicacion avisa al cliente que hay algo por purgar. En false,
        // no avisa: el sistema nunca borra historia clinica por su cuenta.
        'avisar_antes_de_purgar' => (bool) env('CLINICA_DENTAL_AVISAR_ANTES_DE_PURGAR', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Alertas
    |----------------------------------------------------------------------
    |
    | Umbrales para avisar por volumen de intentos denegados. Los umbrales
    | existen en la configuracion; el AVISO AUTOMATICO NO ESTA IMPLEMENTADO
    | (no hay correo saliente). Ver docs/10.
    |
    */
    'alertas' => [
        'denegados_por_hora' => (int) env('CLINICA_DENTAL_ALERTA_DENEGADOS_POR_HORA', 20),
        'denegados_por_usuario' => (int) env('CLINICA_DENTAL_ALERTA_DENEGADOS_POR_USUARIO', 5),
    ],

    /*
    |----------------------------------------------------------------------
    | Odontograma
    |----------------------------------------------------------------------
    |
    | El mapa dental es historia clinica: se escribe pieza por pieza y solo se
    | corrige con una intervencion nueva, nunca editando el pasado. La
    | migracion 000021 pone los disparadores que lo impeden en la base; esto
    | es solo lo que la aplicacion necesita para explicarlo bien.
    |
    */
    'odontograma' => [
        'permitir_alta_automatica' => (bool) env('CLINICA_DENTAL_ALTA_ODONTOGRAMA_AUTOMATICA', true),
        'origen_por_defecto' => env('CLINICA_DENTAL_ORIGEN_ODONTOGRAMA', 'nuevo'),
    ],

    /*
    |----------------------------------------------------------------------
    | Zona horaria por omision
    |----------------------------------------------------------------------
    |
    | `consultorios` NO tiene zona horaria: es un dato de infraestructura y
    | todas las instalaciones de Hefesto corren en America/Santiago. Si alguna
    | vez hay una instalacion fuera, es una migracion, no una variable.
    |
    */
    'zona_horaria' => env('APP_TIMEZONE', 'America/Santiago'),

];