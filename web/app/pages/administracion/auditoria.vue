<script setup lang="ts">
/**
 * Bitacora de accesos al historial odontologico.
 *
 * SOLO EL ADMINISTRADOR, Y NO POR JERARQUIA
 * -----------------------------------------
 * La bitacora dice quién abrió el historial de quién. Eso es información sobre
 * las personas de la institución tanto como sobre los pacientes. Si la pudiera
 * leer un profesional, dejaría de ser un control y pasaría a ser un
 * instrumento para vigilar al personal, que es exactamente para lo que no debe
 * servir.
 *
 * Y el administrador, que puede leer esto, NO puede leer una historia clínica.
 * Son cosas distintas: una es información de salud del paciente, la otra es
 * información sobre el uso del sistema. Ver docs/07.
 *
 * LO QUE DEVUELVE Y LO QUE NO
 * ---------------------------
 * Cuentas, no el detalle fila por fila. El detalle existe en la tabla
 * `accesos_odontograma` y existe para una revisión formal, no para una pantalla
 * de uso diario: una pantalla con el detalle es una pantalla que se puede dejar
 * abierta, y esa pantalla revela información de salud de pacientes.
 */
definePageMeta({ middleware: 'auth' })

const { puedeVerAuditoria } = useSesion()

const desde = ref(new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10))
const hasta = ref(new Date().toISOString().slice(0, 10))

interface Resumen {
    rango: { desde: string; hasta: string }
    total: number
    por_resultado: Record<string, number>
    por_accion: Record<string, number>
    por_usuario: Array<{
        usuario_id: string | null
        nombre: string
        rol: string | null
        total: number
    }>
    denegados: number
    usuarios_sobre_el_umbral: number
    umbral_denegados_por_usuario: number
    acciones_registradas: Array<{ valor: string; etiqueta: string }>
    resultados_registrados: Array<{ valor: string; etiqueta: string }>
    aviso_automatico_implementado: boolean
    nota: string
}

const { data: resumen, refresh } = await useAsyncData(
    'auditoria',
    () =>
        $fetch<Resumen>('/api/administracion/auditoria', {
            query: { desde: desde.value, hasta: hasta.value },
        }),
    { watch: [desde, hasta] },
)

function etiquetaResultado(valor: string): string {
    return resumen.value?.resultados_registrados.find((r) => r.valor === valor)?.etiqueta ?? valor
}

function etiquetaAccion(valor: string): string {
    return resumen.value?.acciones_registradas.find((a) => a.valor === valor)?.etiqueta ?? valor
}
</script>

<template>
    <section>
        <h1>Bitácora de accesos</h1>

        <div v-if="!puedeVerAuditoria" class="panel">
            <div class="vacio">
                La bitácora la lee el administrador del consultorio. No es una medida de
                jerarquía: la bitácora dice quién abrió el historial de quién, y eso es
                información sobre las personas de la institución tanto como sobre los pacientes.
            </div>
        </div>

        <template v-else>
            <div class="panel">
                <div class="campo--linea">
                    <div class="campo">
                        <label for="desde">Desde</label>
                        <input id="desde" v-model="desde" type="date" />
                    </div>

                    <div class="campo">
                        <label for="hasta">Hasta</label>
                        <input id="hasta" v-model="hasta" type="date" />
                    </div>
                </div>

                <p class="ayuda">
                    {{ resumen?.rango.desde }} a {{ resumen?.rango.hasta }} ·
                    {{ resumen?.total ?? 0 }} accesos registrados.
                </p>
            </div>

            <div class="rejillas">
                <div
                    class="tarjeta"
                    :class="(resumen?.denegados ?? 0) > 0 ? 'tarjeta--riesgo' : 'tarjeta--ok'"
                >
                    <p class="tarjeta__titulo">Intentos denegados</p>
                    <p class="tarjeta__cifra">{{ resumen?.denegados ?? 0 }}</p>
                    <p class="tarjeta__nota">
                        Un volumen alto es la señal de que alguien está probando el sistema con
                        el rol equivocado.
                    </p>
                </div>

                <div
                    class="tarjeta"
                    :class="(resumen?.usuarios_sobre_el_umbral ?? 0) > 0 ? 'tarjeta--alerta' : 'tarjeta--ok'"
                >
                    <p class="tarjeta__titulo">Personas sobre el umbral</p>
                    <p class="tarjeta__cifra">{{ resumen?.usuarios_sobre_el_umbral ?? 0 }}</p>
                    <p class="tarjeta__nota">
                        Umbral configurado: {{ resumen?.umbral_denegados_por_usuario }} accesos en el
                        rango.
                    </p>
                </div>
            </div>

            <div v-if="!resumen?.aviso_automatico_implementado" class="panel">
                <h2>Revisión manual</h2>
                <p class="ayuda">{{ resumen?.nota }}</p>
                <p class="ayuda">
                    Dos revisiones que conviene hacer:
                </p>
                <ul class="ayuda">
                    <li>
                        <strong>Mensual:</strong> intentos denegados por persona. Si aparecen muchos
                        de la misma persona, hay que conversar con ella o corregir sus permisos.
                    </li>
                    <li>
                        <strong>Bajo demanda:</strong> accesos a un paciente concreto, por ejemplo
                        cuando el paciente o su familia pregunta quién miró su ficha. La respuesta
                        tiene que salir de acá, no de la memoria.
                    </li>
                </ul>
            </div>

            <div class="panel">
                <h2>Por resultado</h2>

                <table>
                    <thead>
                        <tr>
                            <th>Resultado</th>
                            <th>Accesos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(total, resultado) in resumen?.por_resultado ?? {}" :key="resultado">
                            <td>{{ etiquetaResultado(resultado) }}</td>
                            <td>{{ total }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <h2>Por acción</h2>

                <table>
                    <thead>
                        <tr>
                            <th>Acción</th>
                            <th>Accesos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(total, accion) in resumen?.por_accion ?? {}" :key="accion">
                            <td>{{ etiquetaAccion(accion) }}</td>
                            <td>{{ total }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <h2>Por persona</h2>

                <table>
                    <thead>
                        <tr>
                            <th>Persona</th>
                            <th>Rol</th>
                            <th>Accesos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="fila in resumen?.por_usuario ?? []" :key="fila.usuario_id ?? 'sin'">
                            <td>{{ fila.nombre }}</td>
                            <td>{{ fila.rol ?? '—' }}</td>
                            <td>{{ fila.total }}</td>
                        </tr>

                        <tr v-if="(resumen?.por_usuario ?? []).length === 0">
                            <td colspan="3" class="vacio">
                                No hay accesos registrados en este rango.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </section>
</template>