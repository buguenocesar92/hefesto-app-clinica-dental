<script setup lang="ts">
/**
 * Tarifario del consultorio.
 *
 * UNA TARIFA ES UN DATO, NO UN COBRO
 * ---------------------------------
 * Aca se guarda el precio de un procedimiento. La FACTURACIÓN NO ESTÁ
 * IMPLEMENTADA: no hay cobro, ni boleta, ni conexión con el sistema de
 * facturación de Hefesto. Si alguien pregunta por cobrar, la respuesta es que el
 * precio está acá y el cobro se hace por otro lado. Ver docs/10.
 *
 * LA VIGENCIA ES POR FECHA
 * ------------------------
 * El mismo procedimiento puede tener varias tarifas con `vigente_desde`
 * distintos, que es como funcionan las listas de precios en la práctica. La
 * vigente es la más reciente cuya fecha no sea futura. Cambiar el precio no
 * reescribe el pasado: la tarifa vieja queda.
 */
import type { Procedimiento, Tarifa } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const { puedeAdministrar } = useSesion()

const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

const { data: tarifas, refresh } = await useAsyncData('tarifas', () =>
    $fetch<{ total: number; fecha_de_corte: string; datos: Tarifa[] }>('/api/administracion/tarifas'),
)

const { data: catalogo } = await useAsyncData('procedimientos-tarifa', () =>
    $fetch<{ datos: Procedimiento[] }>('/api/administracion/procedimientos', { query: { activo: true } }),
)

const nueva = reactive({
    procedimiento_id: '',
    nombre: '',
    precio_clp: 0,
    vigente_desde: new Date().toISOString().slice(0, 10),
})

async function crear() {
    error.value = null
    mensaje.value = null

    try {
        await $fetch('/api/administracion/tarifas', {
            method: 'POST',
            body: {
                procedimiento_id: nueva.procedimiento_id,
                nombre: nueva.nombre,
                precio_clp: nueva.precio_clp,
                vigente_desde: nueva.vigente_desde,
            },
        })

        mensaje.value = 'Tarifa registrada.'
        nueva.precio_clp = 0
        nueva.nombre = ''

        await refresh()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo registrar.'
    }
}

function pesos(monto: number): string {
    return new Intl.NumberFormat('es-CL').format(monto)
}
</script>

<template>
    <section>
        <h1>Tarifario</h1>

        <div v-if="!puedeAdministrar" class="panel">
            <div class="vacio">La administración del consultorio es del rol administrador.</div>
        </div>

        <template v-else>
            <div v-if="mensaje" class="aviso">{{ mensaje }}</div>
            <div v-if="error" class="error">{{ error }}</div>

            <div class="panel">
                <p class="ayuda">
                    Corte al {{ tarifas?.fecha_de_corte }}. La facturación no está implementada:
                    este es el precio de referencia, no un cobro.
                </p>

                <table>
                    <thead>
                        <tr>
                            <th>Procedimiento</th>
                            <th>Nombre</th>
                            <th>Precio</th>
                            <th>Vigente desde</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tarifa in tarifas?.datos ?? []" :key="tarifa.id">
                            <td>
                                <template v-if="tarifa.procedimiento">
                                    {{ tarifa.procedimiento.codigo }} · {{ tarifa.procedimiento.nombre }}
                                </template>
                                <template v-else>Procedimiento eliminado</template>
                            </td>
                            <td>{{ tarifa.nombre }}</td>
                            <td>{{ pesos(tarifa.precio_clp) }} pesos</td>
                            <td>{{ tarifa.vigente_desde ?? '—' }}</td>
                            <td>
                                <span v-if="tarifa.es_la_vigente" class="etiqueta etiqueta--exito">
                                    Vigente
                                </span>
                                <span v-else-if="!tarifa.activo" class="etiqueta">Inactiva</span>
                                <span v-else class="etiqueta">Anterior</span>
                            </td>
                        </tr>

                        <tr v-if="(tarifas?.datos ?? []).length === 0">
                            <td colspan="5" class="vacio">No hay tarifas registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <h2>Registrar una tarifa</h2>

                <p class="ayuda">
                    Para cambiar el precio de un procedimiento no se edita la tarifa vieja: se
                    registra una nueva con la fecha desde la que empieza a regir. Así queda
                    claro qué se cobraba antes y cuándo cambió.
                </p>

                <form @submit.prevent="crear">
                    <div class="campo--linea">
                        <div class="campo">
                            <label for="procedimiento">Procedimiento</label>
                            <select id="procedimiento" v-model="nueva.procedimiento_id" required>
                                <option value="">Elija un procedimiento</option>
                                <option
                                    v-for="p in catalogo?.datos ?? []"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.codigo }} · {{ p.nombre }}
                                </option>
                            </select>
                        </div>

                        <div class="campo">
                            <label for="nombre-tarifa">Nombre de la tarifa</label>
                            <input id="nombre-tarifa" v-model="nueva.nombre" required maxlength="120" />
                        </div>
                    </div>

                    <div class="campo--linea">
                        <div class="campo">
                            <label for="precio">Precio en pesos</label>
                            <input id="precio" v-model.number="nueva.precio_clp" type="number" min="0" required />
                        </div>

                        <div class="campo">
                            <label for="vigente">Vigente desde</label>
                            <input id="vigente" v-model="nueva.vigente_desde" type="date" required />
                        </div>
                    </div>

                    <button type="submit" class="boton">Registrar tarifa</button>
                </form>
            </div>
        </template>
    </section>
</template>