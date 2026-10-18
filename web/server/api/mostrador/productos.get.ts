/**
 * El catalogo del mostrador.
 *
 * Este endpoint NO lee ninguna ficha clinica. Quien cobra necesita el precio y
 * el stock, y nada mas.
 *
 * Si pudiera devolver tambien el historial de la mascota que se esta
 * despachando, el mostrador sabria que animal esta bajo de peso o que perro
 * tiene una enfermedad cronica. Eso es informacion de salud, y no hace falta
 * para cobrar. Ver docs/03.
 */
import { llamarApi } from '../../utils/api'

export default defineEventHandler(async (evento) => {
    const consulta = getQuery(evento)

    return llamarApi(evento, '/mostrador/productos', {
        consulta: { q: consulta.q as string | undefined },
    })
})
