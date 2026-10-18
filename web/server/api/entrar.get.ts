/**
 * El punto de entrada a la API, desde el navegador.
 *
 * Por que existe un endpoint intermediario y no un `location.href` directo a
 * la API: para que el navegador nunca vea la URL interna de la API. Va a
 * Nuxt, Nuxt reenvia la cookie a la API, y la API responde con la
 * redireccion a Authentik.
 */
import { llamarApi } from '../../utils/api'

export default defineEventHandler(async (evento) => {
    await llamarApi(evento, '/auth/login')

    // La API responde con una redireccion a Authentik. Se pasa tal cual.
    return sendRedirect(evento, '/agenda')
})
