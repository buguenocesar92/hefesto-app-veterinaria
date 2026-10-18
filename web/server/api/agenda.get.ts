/**
 * La agenda del dia.
 *
 * La cache esta en la API (Redis), no aqui. Este endpoint no cachea nada: si
 * lo hiciera, una agenda cacheada en Nuxt es una agenda que se le puede
 * mostrar a quien no es de esa clinica. Ver nuxt.config.ts.
 */
import { llamarApi } from '../../utils/api'

export default defineEventHandler(async (evento) => {
    const consulta = getQuery(evento)

    return llamarApi(evento, '/agenda', {
        consulta: {
            fecha: consulta.fecha as string | undefined,
            profesional_id: consulta.profesional_id as string | undefined,
            bloque: consulta.bloque as string | undefined,
        },
    })
})
