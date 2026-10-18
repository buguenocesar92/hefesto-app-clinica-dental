/**
 * Proxy general hacia la API.
 *
 * QUE HACE
 * --------
 * Reenvia TODO lo que llega a `/api/*` al prefijo `/api/v1` de la API de
 * Laravel, arrastrando la cookie de sesion y el token CSRF.
 *
 * POR QUE UN PROXY Y NO UN ARCHIVO POR RUTA
 * -----------------------------------------
 * La API tiene mas de cuarenta rutas y van a crecer. Con un archivo por ruta,
 * cada endpoint nuevo obliga a escribir un archivo en el servidor de Nuxt que
 * solo repite la misma llamada: es la oportunidad de que uno se quede sin
 * escribir y la pantalla falle por algo que no aparece en ninguna busqueda.
 *
 * Con el proxy, la interfaz llama a la API por la misma ruta que documenta
 * `docs/09-API.md`, y no hay una segunda lista que mantener.
 *
 * LO QUE NO HACE
 * ---------------
 * No reescribe las rutas, no normaliza los cuerpos, no transforma los codigos de
 * estado y no "corrige" errores. Un 404 se devuelve como 404. Un 422 con el
 * mensaje del dominio se devuelve con ese mensaje.
 */
export default defineEventHandler(async (evento) => {
    // `/api/...` en el navegador, `/api/v1/...` en la API.
    const ruta = evento.context.params?.ruta ?? ''
    const consulta = getQuery(evento)

    const metodo = evento.method.toUpperCase() as 'GET' | 'POST' | 'PATCH' | 'DELETE'

    // El cuerpo solo se lee en las peticiones que lo traen. En un GET, leerlo
    // haria fallar la llamada.
    const cuerpo = metodo === 'GET' ? undefined : await readBody(evento).catch(() => undefined)

    return await llamarApi(evento, `/${ruta}`, {
        metodo,
        cuerpo,
        consulta: consulta as Record<string, string | number | boolean | undefined>,
    })
})