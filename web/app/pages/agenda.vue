<script setup lang="ts">
/**
 * La agenda del dia.
 *
 * LA PANTALLA QUE MÁS SE ABRE DE TODA LA APLICACIÓN
 * -------------------------------------------------
 * Y la que mejor tiene que verse de reojo: quien la mira esta atendiendo
 * animales mientras la mira.
 *
 * POR QUE EL BLOQUE DE VACUNACIÓN SE SEPARA
 * ------------------------------------------
 * Porque son dos ritmos de trabajo distintos. Una consulta de 40 minutos y una
 * vacuna de 10, en la misma lista, hacen que la gente que va por una vacuna
 * espere como si fuera consulta.
 *
 * Aqui solo se FILTRA lo que ya vino de la API. La separacion real esta en
 * `tipos_atencion.bloque_vacunacion` y en la constraint de solapamiento de la
 * tabla `citas`. Ver docs/02.
 */
interface Cita {
    id: string
    inicio: string | null
    fin: string | null
    estado: string
    bloque_vacunacion: boolean
    tipo_atencion: string | null
    mascota: string | null
    especie: string | null
    tutor: string | null
    telefono: string | null
    profesional: string | null
    motivo: string | null
}

interface RespuestaAgenda {
    fecha: string
    profesionales: { id: string; nombre: string; especialidad: string | null }[]
    citas: Cita[]
}

const fecha = ref(new Date().toISOString().slice(0, 10))
const bloque = ref<'todos' | 'vacunacion' | 'consultas'>('todos')

const { data, error } = await useFetch<RespuestaAgenda>('/api/agenda', {
    query: { fecha, bloque },
})

const citas = computed(() => data.value?.citas ?? [])

function hora(iso: string | null): string {
    if (!iso) return '—'

    return new Date(iso).toLocaleTimeString('es-CL', {
        hour: '2-digit',
        minute: '2-digit',
    })
}
</script>

<template>
    <div>
        <h1>Agenda del {{ fecha }}</h1>

        <div class="controles">
            <label>
                Fecha
                <input v-model="fecha" type="date" />
            </label>

            <label>
                Bloque
                <select v-model="bloque">
                    <option value="todos">Todo</option>
                    <option value="consultas">Consultas</option>
                    <option value="vacunacion">Vacunación</option>
                </select>
            </label>
        </div>

        <p v-if="error" class="aviso">
            No se pudo cargar la agenda. {{ error.message }}
        </p>

        <p v-else-if="citas.length === 0" class="vacio">
            No hay citas para este día.
        </p>

        <table v-else>
            <thead>
                <tr>
                    <th>Hora</th>
                    <th>Mascota</th>
                    <th>Tutor</th>
                    <th>Atención</th>
                    <th>Profesional</th>
                    <th>Motivo</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="cita in citas" :key="cita.id">
                    <td>{{ hora(cita.inicio) }}</td>
                    <td>
                        {{ cita.mascota }}
                        <small v-if="cita.especie">({{ cita.especie }})</small>
                    </td>
                    <td>
                        {{ cita.tutor }}
                        <small v-if="cita.telefono">· {{ cita.telefono }}</small>
                    </td>
                    <td>
                        {{ cita.tipo_atencion }}
                        <span v-if="cita.bloque_vacunacion" class="etiqueta-vacuna">
                            vacuna
                        </span>
                    </td>
                    <td>{{ cita.profesional }}</td>
                    <td>{{ cita.motivo ?? '—' }}</td>
                    <td>{{ cita.estado }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.controles {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    align-items: flex-end;
}

.etiqueta-vacuna {
    display: inline-block;
    font-size: 0.72rem;
    background: #e3efe7;
    color: #1e5a35;
    border-radius: 0.25rem;
    padding: 0.05rem 0.35rem;
    margin-left: 0.3rem;
}
</style>
