<script setup lang="ts">
/**
 * La ficha clinica de UNA mascota.
 *
 * LA REGLA DE ESTA PANTALLA
 * ------------------------
 * El identificador que viaja en la URL es el de la MASCOTA, no el del tutor.
 *
 * Es la misma regla que en la API, por la misma razon: un tutor con tres
 * animales tiene tres historiales separados. Si esta pantalla pidiera "el
 * historial de este tutor", mostraría los tres mezclados en una sola línea del
 * tiempo, y al recetar se calcularía sobre el animal equivocado.
 *
 * El error más grave de este dominio es de buena fe: no un atacante, sino
 * alguien que abre la ficha de Firulais y el sistema le muestra también la de
 * Michi. Por eso la API filtra por `mascota_id` en todas partes, y hay una
 * prueba que lo vigila.
 *
 * QUIEN LLEGA AQUI
 * ----------------
 * Solo un profesional. Si no, la API responde 404 y esta pantalla lo muestra
 * como "No encontrado". El acceso, permitido o denegado, queda registrado.
 */
const ruta = useRoute()
const idMascota = computed(() => String(ruta.params.id))

const { data, error } = await useFetch<Historial>(`/api/fichas/${idMascota.value}`)

interface Mascota {
    id: string
    nombre: string
    especie_etiqueta: string
    raza: string | null
    edad_meses: number | null
    peso_kg: string | null
    identificacion: string
}

interface Consulta {
    id: string
    fecha: string | null
    motivo_consulta: string | null
    diagnostico: string | null
    tratamiento: string | null
    profesional: string | null
}

interface Vacuna {
    id: string
    nombre_vacuna: string | null
    fecha_aplicacion: string | null
    proxima_dose_en: string | null
    lote: string | null
    vigencia: string
}

interface Historial {
    mascota: Mascota
    tutor: { id: string; nombre: string; telefono: string | null }
    consultas: Consulta[]
    vacunas: Vacuna[]
}

function fecha(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('es-CL') : '—'
}
</script>

<template>
    <div>
        <p v-if="error" class="aviso">
            No se encontró esta ficha. Si crees que deberías verla, habla con la
            administración de la clínica.
        </p>

        <template v-else-if="data">
            <h1>{{ data.mascota.identificacion }}</h1>
            <p class="subtitulo">
                Tutor: {{ data.tutor.nombre }}
                <span v-if="data.tutor.telefono">· {{ data.tutor.telefono }}</span>
            </p>

            <h2>Consultas</h2>
            <p v-if="data.consultas.length === 0" class="vacio">
                Esta mascota no tiene consultas registradas.
            </p>

            <article v-for="consulta in data.consultas" :key="consulta.id" class="consulta">
                <header>
                    <strong>{{ fecha(consulta.fecha) }}</strong>
                    <span>{{ consulta.profesional }}</span>
                </header>
                <p v-if="consulta.motivo_consulta">
                    <em>Motivo:</em> {{ consulta.motivo_consulta }}
                </p>
                <p v-if="consulta.diagnostico">
                    <em>Diagnóstico:</em> {{ consulta.diagnostico }}
                </p>
                <p v-if="consulta.tratamiento">
                    <em>Tratamiento:</em> {{ consulta.tratamiento }}
                </p>
            </article>

            <h2>Vacunas</h2>
            <table v-if="data.vacunas.length > 0">
                <thead>
                    <tr>
                        <th>Vacuna</th>
                        <th>Aplicada</th>
                        <th>Próxima dosis</th>
                        <th>Lote</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="vacuna in data.vacunas" :key="vacuna.id">
                        <td>{{ vacuna.nombre_vacuna ?? 'Sin esquema' }}</td>
                        <td>{{ fecha(vacuna.fecha_aplicacion) }}</td>
                        <td>{{ fecha(vacuna.proxima_dose_en) }}</td>
                        <td>{{ vacuna.lote ?? '—' }}</td>
                        <td :class="`estado-${vacuna.vigencia}`">
                            {{ vacuna.vigencia }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="vacio">Sin vacunas registradas.</p>
        </template>
    </div>
</template>

<style scoped>
.subtitulo {
    color: #555;
    margin-top: -0.5rem;
}

.consulta {
    background: #fff;
    border: 1px solid var(--borde);
    border-radius: 0.4rem;
    padding: 0.75rem 0.9rem;
    margin-bottom: 0.6rem;
}

.consulta header {
    display: flex;
    gap: 0.75rem;
    color: #555;
    font-size: 0.9rem;
    margin-bottom: 0.35rem;
}

.consulta p {
    margin: 0.2rem 0;
}
</style>
