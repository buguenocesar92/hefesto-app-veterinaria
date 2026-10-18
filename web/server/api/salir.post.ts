/**
 * Cierra la sesion.
 *
 * El token CSRF viaja en la cookie `XSRF-TOKEN`, y `llamarApi` lo reenvia
 * como cabecera. Sin eso, este POST devolveria 419.
 */
import { llamarApi } from '../utils/api'

export default defineEventHandler(async (evento) => {
    return llamarApi<{ mensaje: string }>(evento, '/auth/logout', {
        metodo: 'POST',
    })
})
