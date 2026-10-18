/**
 * Guardia de las pantallas que necesitan sesion.
 *
 * QUE HACE Y QUE NO HACE
 * ----------------------
 * Redirige al ingreso cuando no hay sesion. Eso es todo.
 *
 * NO es una medida de seguridad. Una pantalla sin sesion a la que se le pueda
 * pedir un dato sensible seria un agujero, y por eso el permiso de verdad se
 * comprueba en la API: el middleware corre en el navegador, y el navegador es
 * del otro lado. Ver docs/03.
 *
 * La razon de que exista igual es la experiencia: una pantalla vacia con el menu
 * y sin datos es peor que una pantalla de ingreso clara.
 */
export default defineNuxtRouteMiddleware(async (to) => {
    // La propia pantalla de ingreso no se protege: seria un bucle.
    if (to.path === '/entrar') {
        return
    }

    const { usuario, cargar } = useSesion()

    // En el servidor, el estado ya viene resuelto desde la carga inicial.
    if (import.meta.server && usuario.value !== null) {
        return
    }

    await cargar()

    if (usuario.value === null) {
        return navigateTo('/entrar')
    }
})