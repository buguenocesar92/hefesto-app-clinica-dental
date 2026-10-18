<script setup lang="ts">
/**
 * Historial clinico completo de un paciente, agrupado por fecha.
 *
 * ESTA PAGINA ES INFORMACION DE SALUD
 * -----------------------------------
 * Solo la abre quien tiene rol profesional o administrador, y CADA apertura queda
 * anotada en la bitacora de accesos, con el nombre de la persona, la hora y la
 * direccion desde la que se hizo. Si la API responde 404, la pantalla muestra
 * que no puede: no puede distinguir "no existe" de "no lo puede ver", y no debe
 * intentarlo.
 *
 * POR QUE AGRUPADA POR FECHA
 * -------------------------
 * La pregunta que se le hace a un sistema odontologico casi nunca es "que le
 * hicieron en el molar de arriba a la derecha". Es "que le hicimos en esa
 * visita". Por eso el historial se lee por fecha y las piezas aparecen dentro de
 * cada dia.
 *
 * Ver docs/03 y docs/07.
 */
import type { Pieza } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const ruta = useRoute()
const id = String(ruta.params.id)

const { data: mapa, error: errorMapa } = await useAsyncData(`mapa-historial-${id}`, () =>
    $fetch<{ piezas: Pieza[]; odontograma: { id: string } }>(`/api/pacientes/${id}/odontograma`),
)

interface LineaHistorial {
    id: string
    hora: string | null
    numero_fdi: number
    pieza: string
    descripcion: string
    estado_anterior: string | null
    estado_resultante: string
    profesional: string | null
}

interface RespuestaHistorial {
    paciente: { id: string; nombre: string }
    total: number
    por_fecha: Record<string, LineaHistorial[]>
}

const { data: historial, error: errorHistorial } = await useAsyncData(
    `historial-${id}`,
    () => $fetch<RespuestaHistorial>(`/api/pacientes/${id}/historial/${mapa.value?.odontograma.id}`),
    { watch: [() => mapa.value?.odontograma.id] },
)

/** Fechas ordenadas de la mas reciente a la mas antigua. */
const fechas = computed(() => Object.keys(historial.value?.por_fecha ?? {}).sort().reverse())

/** Pieza a la que pertenece una linea, para ver su estado actual. */
function estadoActual(numeroFdi: number): string | null {
    const pieza = (mapa.value?.piezas ?? []).find((p) => p.numero_fdi === numeroFdi)

    return pieza ? pieza.estado_etiqueta : null
}
</script>

<template>
    <section>
        <p class="ayuda">
            <NuxtLink :to="`/pacientes/${id}`">← Volver a la ficha</NuxtLink>
        </p>

        <h1>Historial odontológico</h1>

        <p class="ayuda">
            <strong>{{ historial?.paciente.nombre }}</strong> ·
            {{ historial?.total ?? 0 }} intervenciones registradas.
        </p>

        <div v-if="errorMapa || errorHistorial" class="error">
            No se pudo abrir el historial.
            <div class="error__detalle">
                Si el paciente existe y su rol no alcanza, la API responde 404 a propósito: un
                403 confirmaría que el recurso existe. Si no existe, es lo mismo que ve quien
                no tiene permiso.
            </div>
        </div>

        <div v-if="fechas.length === 0 && !errorHistorial" class="panel">
            <div class="vacio">
                Todavía no hay intervenciones registradas para este paciente.
            </div>
        </div>

        <div v-for="fecha in fechas" :key="fecha" class="panel">
            <h2>{{ fecha }}</h2>

            <table>
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Pieza</th>
                        <th>Qué se hizo</th>
                        <th>Cambio de estado</th>
                        <th>Profesional</th>
                        <th>Hoy está</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="linea in historial?.por_fecha[fecha] ?? []" :key="linea.id">
                        <td>{{ linea.hora ?? '—' }}</td>
                        <td>{{ linea.pieza }}</td>
                        <td>{{ linea.descripcion }}</td>
                        <td>
                            <span v-if="linea.estado_anterior">
                                {{ linea.estado_anterior }} → <strong>{{ linea.estado_resultante }}</strong>
                            </span>
                            <span v-else>{{ linea.estado_resultante }}</span>
                        </td>
                        <td>{{ linea.profesional ?? '—' }}</td>
                        <td>{{ estadoActual(linea.numero_fdi) ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>