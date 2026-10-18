<script setup lang="ts">
/**
 * Insumos del consultorio.
 *
 * EL STOCK NO SE DESCUENTA SOLO
 * -----------------------------
 * Hoy el ajuste es manual, y por eso el ajuste tiene su propio formulario con
 * motivo obligatorio: una resta sin explicación es donde se esconden las
 * diferencias de inventario.
 *
 * Que el consumo se descuente solo desde el registro de la intervención NO está
 * implementado. Queda anotado en docs/10 como pendiente, y está escrito acá
 * porque un inventario que se descuenta a mano se desincroniza, y conviene que
 * quien lo use lo sepa.
 */
import type { Insumo } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const { puedeAdministrar } = useSesion()

const soloBajoStock = ref(false)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

const { data: insumos, refresh } = await useAsyncData('insumos', () =>
    $fetch<{ total: number; bajo_stock: number; datos: Insumo[] }>('/api/administracion/insumos', {
        query: { bajo_stock: soloBajoStock.value ? true : undefined, activo: true },
    }),
    { watch: [soloBajoStock] },
)

const ajuste = reactive({
    id: '',
    cantidad: 0,
    motivo: '',
})

const visibles = computed(() => (insumos.value?.datos ?? []).filter((i) => !soloBajoStock.value || i.bajo_stock))

async function registrarAjuste() {
    if (ajuste.id === '' || ajuste.motivo.trim().length < 10) {
        return
    }

    error.value = null
    mensaje.value = null

    try {
        await $fetch(`/api/administracion/insumos/${ajuste.id}/stock`, {
            method: 'POST',
            body: {
                cantidad: ajuste.cantidad,
                motivo: ajuste.motivo.trim(),
            },
        })

        mensaje.value = 'Stock ajustado.'
        ajuste.cantidad = 0
        ajuste.motivo = ''

        await refresh()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo ajustar.'
    }
}
</script>

<template>
    <section>
        <h1>Insumos</h1>

        <div v-if="!puedeAdministrar" class="panel">
            <div class="vacio">La administración del consultorio es del rol administrador.</div>
        </div>

        <template v-else>
            <div v-if="mensaje" class="aviso">{{ mensaje }}</div>
            <div v-if="error" class="error">{{ error }}</div>

            <div class="panel">
                <div class="campo" style="max-width: 260px;">
                    <label for="bajo">Ver</label>
                    <select id="bajo" v-model="soloBajoStock">
                        <option :value="false">Todos los insumos</option>
                        <option :value="true">Solo los que están bajo el mínimo</option>
                    </select>
                </div>

                <p v-if="(insumos?.bajo_stock ?? 0) > 0" class="error">
                    {{ insumos?.bajo_stock }} insumo(s) están por debajo del mínimo.
                </p>

                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Insumo</th>
                            <th>Stock</th>
                            <th>Mínimo</th>
                            <th>Unidad</th>
                            <th>Última carga</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="insumo in visibles" :key="insumo.id">
                            <td>{{ insumo.codigo }}</td>
                            <td>{{ insumo.nombre }}</td>
                            <td>
                                <span class="etiqueta" :class="insumo.bajo_stock ? 'etiqueta--riesgo' : ''">
                                    {{ insumo.stock_actual }}
                                </span>
                            </td>
                            <td>{{ insumo.stock_minimo }}</td>
                            <td>{{ insumo.unidad }}</td>
                            <td>{{ insumo.fecha_ultima_carga ?? '—' }}</td>
                            <td>
                                <span v-if="insumo.bajo_stock" class="etiqueta etiqueta--riesgo">
                                    Reponer
                                </span>
                                <span v-else class="etiqueta etiqueta--exito">Ok</span>
                            </td>
                        </tr>

                        <tr v-if="visibles.length === 0">
                            <td colspan="7" class="vacio">No hay insumos que coincidan.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <h2>Ajustar stock</h2>

                <p class="ayuda">
                    La cantidad es lo que se <strong>suma</strong>. Puede ser negativa para dejar
                    una merma, y por eso el motivo es obligatorio: una resta sin explicación es
                    donde se esconden las diferencias.
                </p>

                <form @submit.prevent="registrarAjuste">
                    <div class="campo--linea">
                        <div class="campo">
                            <label for="insumo">Insumo</label>
                            <select id="insumo" v-model="ajuste.id" required>
                                <option value="">Elija un insumo</option>
                                <option
                                    v-for="insumo in insumos?.datos ?? []"
                                    :key="insumo.id"
                                    :value="insumo.id"
                                >
                                    {{ insumo.nombre }} (stock {{ insumo.stock_actual }})
                                </option>
                            </select>
                        </div>

                        <div class="campo">
                            <label for="cantidad">Cantidad</label>
                            <input id="cantidad" v-model.number="ajuste.cantidad" type="number" required />
                        </div>
                    </div>

                    <div class="campo">
                        <label for="motivo-ajuste">Motivo (obligatorio)</label>
                        <input id="motivo-ajuste" v-model="ajuste.motivo" required minlength="10" />
                    </div>

                    <button
                        type="submit"
                        class="boton"
                        :disabled="ajuste.id === '' || ajuste.motivo.trim().length < 10"
                    >
                        Registrar ajuste
                    </button>
                </form>
            </div>
        </template>
    </section>
</template>