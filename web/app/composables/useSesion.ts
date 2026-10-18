/**
 * Composable de sesion.
 *
 * SOLO LECTURA Y NAVEGACION
 * -------------------------
 * Los permisos vienen de la API. Este archivo esconde botones, no protege datos.
 * Que un boton no aparezca no impide que alguien llame a la API directamente, y
 * por eso cada ruta del servidor vuelve a preguntar al servidor. Ver docs/03.
 */

import type { UsuarioSesion } from '~/tipos/api'

export function useSesion() {
    const usuario = useState<UsuarioSesion | null>('usuario', () => null)

    /**
     * Pide los datos de la persona al servidor.
     *
     * Un 401 o un 404 se tratan igual: sin sesion no hay nada que mostrar, y la
     * pantalla de ingreso es el unico lugar donde tiene sentido estar.
     */
    async function cargar(): Promise<UsuarioSesion | null> {
        try {
            usuario.value = await $fetch<UsuarioSesion>('/api/auth/usuario')
        } catch {
            usuario.value = null
        }

        return usuario.value
    }

    async function cerrarSesion(): Promise<void> {
        usuario.value = null

        await navigateTo('/entrar')
    }

    const capacidades = computed(() => usuario.value?.capacidades ?? null)

    /**
     * Cada permiso por separado.
     *
     * Se nombran por lo que hacen y no por el rol, porque el rol es como se
     * organiza el consultorio y la capacidad es lo que la persona puede hacer.
     * Mañana el rol puede cambiar sin que cambie lo que se necesita.
     */
    const puedeVerEstadoActual = computed(() => capacidades.value?.ver_estado_actual === true)
    const puedeVerCronologia = computed(() => capacidades.value?.ver_cronologia === true)
    const puedeRegistrarIntervencion = computed(() => capacidades.value?.registrar_intervencion === true)
    const puedeVerPlanes = computed(() => capacidades.value?.ver_planes === true)
    const puedeEditarPlanes = computed(() => capacidades.value?.editar_planes === true)
    const puedeDesbloquearAlergias = computed(() => capacidades.value?.desbloquear_alergias === true)
    const puedeAdministrar = computed(() => capacidades.value?.administra === true)
    const puedeVerAuditoria = computed(() => capacidades.value?.ver_auditoria === true)

    return {
        usuario,
        cargar,
        cerrarSesion,
        puedeVerEstadoActual,
        puedeVerCronologia,
        puedeRegistrarIntervencion,
        puedeVerPlanes,
        puedeEditarPlanes,
        puedeDesbloquearAlergias,
        puedeAdministrar,
        puedeVerAuditoria,
    }
}