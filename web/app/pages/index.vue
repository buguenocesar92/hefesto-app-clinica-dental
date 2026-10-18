<script setup lang="ts">
/**
 * Panel de inicio: que hay que mirar hoy.
 *
 * POR QUE SON TARJETAS Y NO NUMEROS SUELTOS
 * ------------------------------------------
 * Un "hay 214 pacientes" no dice nada. "Hay 2 pacientes con alergia grave con
 * cita esta semana" si. Por eso cada tarjeta trae `requiere_review` y se pinta
 * sola: lo que hay que mirar queda a la vista sin que nadie tenga que buscarlo.
 *
 * LO QUE NO SE MUESTRA ACA
 * -------------------------
 * Ningun dato de historia clinica. El panel cuenta; la cronologia se abre en su
 * propia pantalla, que exige permiso y queda auditada. Ver docs/03.
 */
import type { Panel } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const { usuario } = useSesion()

const { data: panel, error } = await useAsyncData('panel', () => $fetch<Panel>('/api/panel'))

/**
 * Un bloque del panel, con su color.
 *
 * Tres colores y ninguno mas: bien, revisar, urgente. Un tablero con seis
 * colores se lee peor que uno con tres.
 */
function clase(bloque: { requiere_revision: boolean } | null): string {
    if (bloque === null) {
        return 'tarjeta tarjeta--ok'
    }

    return bloque.requiere_revision ? 'tarjeta tarjeta--riesgo' : 'tarjeta tarjeta--ok'
}

/**
 * Los estados de pieza que efectivamente existen en el consultorio.
 *
 * La leyenda completa la manda la API junto con el panel. Filtrar aca evita
 * mostrar quince filas con cero, que es lo que hace que un tablero se deje de
 * mirar.
 */
const estadosPresentes = computed(() => {
    const odontograma = panel.value?.odontograma

    if (!odontograma) {
        return []
    }

    return odontograma.estados_posibles.filter(
        (estado) => (odontograma.por_estado[estado.valor] ?? 0) > 0,
    )
})
</script>

<template>
    <section>
        <h1>Panel</h1>

        <div v-if="error" class="error">
            No se pudo cargar el panel. {{ error.statusMessage ?? '' }}
        </div>

        <template v-if="panel">
            <p class="ayuda">
                {{ panel.consultorio.nombre }} · {{ panel.fecha }} ·
                {{ usuario?.rol_etiqueta }}
            </p>

            <div class="rejillas">
                <div :class="clase(panel.alertas.alergia_grave_esta_semana)">
                    <p class="tarjeta__titulo">Alergia grave con cita esta semana</p>
                    <p class="tarjeta__cifra">{{ panel.alertas.alergia_grave_esta_semana.total ?? 0 }}</p>
                    <p class="tarjeta__nota">
                        {{ panel.alertas.alergia_grave_esta_semana.mensaje }}
                    </p>
                </div>

                <div :class="clase(panel.alertas.condiciones_bloqueantes)">
                    <p class="tarjeta__titulo">Condiciones que bloquean la atención</p>
                    <p class="tarjeta__cifra">{{ panel.alertas.condiciones_bloqueantes.total ?? 0 }}</p>
                    <p class="tarjeta__nota">Registradas y activas en este consultorio.</p>
                </div>

                <div class="tarjeta tarjeta--ok">
                    <p class="tarjeta__titulo">Citas de hoy</p>
                    <p class="tarjeta__cifra">{{ panel.citas_hoy.total }}</p>
                    <p class="tarjeta__nota">
                        {{ panel.citas_hoy.ocupa_agenda }} ocupan agenda ·
                        {{ panel.citas_hoy.sobre_desbloqueo }} sobre un desbloqueo
                    </p>
                </div>

                <div :class="clase(panel.pacientes_sin_control)">
                    <p class="tarjeta__titulo">Más tiempo sin control</p>
                    <p class="tarjeta__cifra">
                        {{ panel.pacientes_sin_control.antiguedad_maxima_dias ?? '—' }}
                    </p>
                    <p class="tarjeta__nota">Días desde la última atención de un paciente activo.</p>
                </div>

                <div v-if="panel.alertas.insumos_bajo_stock" :class="clase(panel.alertas.insumos_bajo_stock)">
                    <p class="tarjeta__titulo">Insumos bajo el mínimo</p>
                    <p class="tarjeta__cifra">{{ panel.alertas.insumos_bajo_stock.total ?? 0 }}</p>
                    <p class="tarjeta__nota">Ver la lista de insumos.</p>
                </div>

                <div v-if="panel.alertas.planes_en_curso" :class="clase(panel.alertas.planes_en_curso)">
                    <p class="tarjeta__titulo">Planes en curso</p>
                    <p class="tarjeta__cifra">{{ panel.alertas.planes_en_curso.total ?? 0 }}</p>
                    <p class="tarjeta__nota">
                        {{ panel.alertas.planes_en_curso.etapas_con_fecha_vencida ?? 0 }} etapas con fecha
                        vencida
                    </p>
                </div>
            </div>

            <div v-if="panel.odontograma" class="panel" style="margin-top: 16px;">
                <h2>El consultorio en números</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Estado de pieza</th>
                            <th>Piezas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="estado in estadosPresentes" :key="estado.valor">
                            <td>{{ estado.etiqueta }}</td>
                            <td>{{ panel.odontograma.por_estado[estado.valor] ?? 0 }}</td>
                        </tr>
                        <tr v-if="panel.odontograma.intervenciones_30_dias > 0">
                            <td>Intervenciones en los últimos 30 días</td>
                            <td>{{ panel.odontograma.intervenciones_30_dias }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </section>
</template>