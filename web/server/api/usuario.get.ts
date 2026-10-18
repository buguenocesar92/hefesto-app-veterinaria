/**
 * Quien soy y que puedo hacer.
 *
 * Lo consume `useSesion`. Pasa por el BFF y reenvia la cookie de sesion, asi
 * que el navegador nunca habla directo con la API.
 */
import { llamarApi } from '../utils/api'
import type { Sesion } from '../../app/composables/useSesion'

export default defineEventHandler(async (evento) => {
    return llamarApi<Sesion>(evento, '/auth/usuario')
})
