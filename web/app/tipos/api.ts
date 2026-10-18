/**
 * Tipos de la API de ClinicaDental.
 *
 * ESTOS TIPOS NO SON UNA FUENTE DE PERMISOS
 * ------------------------------------------
 * Describen lo que la API devuelve. Lo que una persona puede hacer lo decide el
 * servidor, en `App\Policies`, y el campo `capacidades` solo existe para que la
 * interfaz no muestre botones que van a ser rechazados.
 *
 * Ocultar un boton NO es una medida de seguridad: alguien puede llamar a la API
 * directamente. Ver docs/03.
 *
 * TODOS LOS CAMPOS VIENEN EN ESPANOL Y CON NUDOS
 * ----------------------------------------------
 * Es la convencion de la API (`snake_case`, `etiqueta`, `valor`). Se mantiene
 * porque traducir en el camino inverso es donde aparecen los errores: un
 * `estado` que en la base es `'severa'` y en la interfaz es un color equivocado
 * termina pareciendo una alergia moderada.
 */

export interface Capacidades {
    ver_estado_actual: boolean
    ver_cronologia: boolean
    registrar_intervencion: boolean
    ver_planes: boolean
    editar_planes: boolean
    desbloquear_alergias: boolean
    administra: boolean
    ver_auditoria: boolean
}

export interface UsuarioSesion {
    id: string
    nombre: string
    correo: string
    rol: 'recepcion' | 'profesional' | 'administrador'
    rol_etiqueta: string
    consultorio: {
        id: string | null
        nombre: string | null
        nombre_corto: string | null
    } | null
    capacidades: Capacidades
}

export interface Consultorio {
    id: string
    nombre: string
    nombre_corto: string
    direccion: string | null
    telefono: string | null
    correo: string | null
    activo: boolean
}

export interface Profesional {
    id: string
    nombre: string
    especialidad: string | null
    registro_profesional: string | null
    registro_verificado: boolean
    correo?: string | null
    telefono?: string | null
    color_agenda: string
    activo: boolean
    citas_totales?: number
    tiene_cuentas?: boolean
}

export interface Procedimiento {
    id: string
    codigo: string
    nombre: string
    categoria: string
    duracion_minutos: number
    es_quirurgico: boolean
    requiere_anamnesis: boolean
    descripcion: string | null
    activo: boolean
    precio_vigente?: number | null
}

export interface Insumo {
    id: string
    codigo: string
    nombre: string
    unidad: string
    stock_actual: number
    stock_minimo: number
    bajo_stock: boolean
    fecha_ultima_carga: string | null
    activo: boolean
}

export interface Tarifa {
    id: string
    nombre: string
    precio_clp: number
    vigente_desde: string | null
    activo: boolean
    es_la_vigente: boolean
    procedimiento: { id: string; codigo: string; nombre: string } | null
}

export interface Paciente {
    id: string
    rut: string | null
    nombre: string
    nombres: string
    apellidos: string
    edad: number | null
    telefono: string | null
    activo: boolean
}

export interface PacienteCompleto extends Paciente {
    fecha_nacimiento: string | null
    sexo: string
    sexo_etiqueta: string
    correo: string | null
    direccion: string | null
    prevision: string | null
    notas_administrativas: string | null
    citas_totales: number
    tiene_odontograma: boolean
    ultima_atencion: string
    dias_sin_control: number | null
}

/** Una linea de la agenda, con el riesgo ya resuelto. */
export interface Obstaculo {
    tipo: 'alergia' | 'condicion'
    registro_id: string
    descripcion: string
}

export interface Cita {
    id: string
    fecha_inicio: string | null
    fecha_fin: string | null
    hora?: string
    hora_fin?: string
    duracion_minutos: number
    estado: string
    estado_etiqueta: string
    ocupa_agenda?: boolean
    silla: string | null
    motivo: string | null
    notas?: string | null
    profesional: { id: string; nombre: string; color_agenda?: string | null } | null
    procedimiento: { id: string; codigo?: string; nombre: string } | null
    paciente: { id: string; nombre: string; telefono: string | null } | null
    puede_atenderse?: boolean
    obstaculos?: Obstaculo[]
    sobre_desbloqueo: boolean
    desbloqueo?: {
        id: string
        motivo: string
        concedido: string | null
        vigente_hasta: string | null
    } | null
}

export interface Alergia {
    id: string
    sustancia: string
    reaccion: string
    severidad: string
    severidad_etiqueta: string
    puede_ser_fatal: boolean
    activa: boolean
    fecha_registro: string | null
    fecha_cierre: string | null
    motivo_cierre: string | null
    notas: string | null
    registrada_por: string | null
    linea_de_bloqueo: string | null
}

export interface Condicion {
    id: string
    condicion: string
    detalle: string | null
    bloquea_atencion: boolean
    requiere_autorizacion_especial: boolean
    referencia_documento: string | null
    activa: boolean
    fecha_registro: string | null
    fecha_cierre: string | null
    motivo_cierre: string | null
    registrada_por: string | null
}

export interface Desbloqueo {
    id: string
    motivo: string
    concedido: string | null
    vigente_hasta: string | null
    vigente_en_hoy?: boolean
    alergia: {
        id: string
        sustancia: string
        reaccion: string
        severidad: string
    } | null
    condicion: { id: string; condicion: string } | null
    procedimiento: string | null
    concedido_por: string | null
}

/** Una pieza del mapa. `notas` va en null si el rol no las abre. */
export interface Pieza {
    numero_fdi: number
    nombre: string
    arcada: 'superior' | 'inferior'
    lado: 'derecha' | 'izquierda'
    estado: string
    estado_etiqueta: string
    abreviatura: string
    estado_desde: string | null
    dias_en_estado: number | null
    notas: string | null
}

export interface EstadoPieza {
    valor: string
    etiqueta: string
    abreviatura: string
    grupo: string
    esta_fuera_de_la_boca?: boolean
    es_reemplazo?: boolean
}

export interface Odontograma {
    id: string
    fecha_alta: string | null
    origen: string
    ultima_intervencion_at: string | null
}

export interface Intervencion {
    id: string
    numero_fdi: number
    pieza?: string
    fecha: string | null
    hora?: string | null
    fecha_registro: string | null
    descripcion: string
    estado_anterior: string | null
    estado_resultante: string
    profesional: string | null
    procedimiento: string | null
    plan: string | null
    notas: string | null
}

export interface Etapa {
    id: string
    orden: number
    nombre: string
    descripcion?: string | null
    numero_fdi: number | null
    estado: string
    estado_etiqueta: string
    fecha_prevista: string | null
    fecha_realizada?: string | null
    presupuesto_clp: number | null
    procedimiento: string | null
}

export interface Plan {
    id: string
    nombre: string
    diagnostico: string | null
    estado: string
    estado_etiqueta: string
    profesional: string | null
    presupuesto_clp: number | null
    fecha_inicio: string | null
    fecha_fin: string | null
    fecha_presentacion: string | null
    fecha_aprobacion: string | null
    observaciones: string | null
    avance: number | null
    etapas: Etapa[]
}

export interface BloqueRevision {
    total?: number
    requiere_revision: boolean
    mensaje?: string
    datos?: Array<Record<string, unknown>>
}

/** Lo que devuelve `GET /api/v1/panel`. */
export interface Panel {
    consultorio: { id: string; nombre: string }
    fecha: string
    rol: string
    capacidades: Capacidades
    citas_hoy: {
        total: number
        por_estado: Record<string, number>
        ocupa_agenda: number
        sobre_desbloqueo: number
    }
    alertas: {
        alergia_grave_esta_semana: BloqueRevision
        condiciones_bloqueantes: BloqueRevision
        insumos_bajo_stock: BloqueRevision | null
        planes_en_curso: BloqueRevision | null
    }
    pacientes_sin_control: BloqueRevision & { antiguedad_maxima_dias: number | null }
    odontograma: {
        odontogramas: number
        piezas_totales: number
        por_estado: Record<string, number>
        intervenciones_30_dias: number
        estados_posibles: EstadoPieza[]
    } | null
}