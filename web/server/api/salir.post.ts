/**
 * Cierre de sesion.
 *
 * El POST va a la API (que invalida la sesion y regenera el token CSRF), y
 * despues el navegador vuelve a la pantalla de ingreso.
 *
 * Va en un archivo aparte y no en el proxy general porque la API responde con
 * una redireccion al login, y lo que interesa aca es devolver al navegador a la
 * pantalla correcta, no el cuerpo de esa redireccion.
 */
export default defineEventHandler(async (evento) => {
    try {
        await llamarApi(evento, '/auth/logout', { metodo: 'POST' })
    } catch {
        // Si la sesion ya estaba vencida, el objetivo de este archivo se
        // cumple igual: el navegador va al ingreso. No se avisa con un error
        // porque a la persona no le sirve de nada saber que su sesion ya habia
        // caducado.
    }

    return sendRedirect(evento, '/entrar')
})