<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'

const router      = useRouter()
const evaluaciones = ref<any[]>([])
const pendientes   = ref<any[]>([]) // asignaciones sin evaluación creada todavía
const loading      = ref(true)
const error        = ref('')
const iniciando     = ref<number | null>(null) // postulacion_id en curso

const estadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', completada: 'badge-blue', cerrada: 'badge-green',
}

onMounted(cargar)

async function cargar() {
  loading.value = true
  try {
    // GET /evaluaciones devuelve las evaluaciones ya iniciadas (asignadas al
    // usuario) con postulacion.plaza, postulacion.convocatoria y
    // postulacion.postulante eager-loaded.
    const { data } = await api.get('/evaluaciones')
    evaluaciones.value = data.data ?? data

    // GET /asignaciones devuelve (para el rol evaluador) solo las propias.
    // Las que todavía no tienen evaluación creada se muestran aparte, con
    // acción para iniciarla.
    const { data: asigData } = await api.get('/asignaciones')
    const asignaciones = asigData.data ?? asigData
    pendientes.value = asignaciones.filter((a: any) => !a.postulacion?.evaluacion)
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al cargar evaluaciones.'
  } finally {
    loading.value = false
  }
}

async function iniciarEvaluacion(postulacionId: number) {
  iniciando.value = postulacionId
  try {
    const { data } = await api.post(`/postulaciones/${postulacionId}/evaluacion`)
    router.push(`/evaluaciones/${data.id}`)
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo iniciar la evaluación')
  } finally {
    iniciando.value = null
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Bandeja de evaluaciones</h1>
        <p>Expedientes asignados para calificar</p>
      </div>
    </div>

    <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

    <div v-else-if="error" class="alert alert-error">{{ error }}</div>

    <div v-else-if="evaluaciones.length === 0 && pendientes.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin evaluaciones asignadas</h3>
        <p>No tienes expedientes pendientes de calificar en este momento.</p>
      </div>
    </div>

    <template v-else>
      <!-- Asignadas pero aún sin iniciar -->
      <div v-if="pendientes.length > 0" class="card mb-4" style="padding:0">
        <div class="card-header">
          <h3 class="card-title">Asignadas — sin iniciar</h3>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Postulante</th>
                <th>Plaza</th>
                <th>Convocatoria</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in pendientes" :key="a.id">
                <td class="font-medium">{{ a.postulacion?.postulante?.name ?? '—' }}</td>
                <td class="text-sm">{{ a.postulacion?.plaza?.asignatura ?? '—' }}</td>
                <td class="text-sm text-muted">{{ a.convocatoria?.codigo ?? '—' }}</td>
                <td>
                  <button
                    class="btn btn-primary btn-sm"
                    :disabled="iniciando === a.postulacion_id"
                    @click="iniciarEvaluacion(a.postulacion_id)"
                  >
                    <span v-if="iniciando === a.postulacion_id" class="spinner"></span>
                    Iniciar evaluación →
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Ya iniciadas -->
      <div v-if="evaluaciones.length > 0" class="card" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Postulante</th>
                <th>Plaza</th>
                <th>Convocatoria</th>
                <th>Puntaje</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ev in evaluaciones" :key="ev.id">
                <td class="font-medium">{{ ev.postulacion?.postulante?.name ?? '—' }}</td>
                <td class="text-sm">{{ ev.postulacion?.plaza?.asignatura ?? '—' }}</td>
                <td class="text-sm text-muted">{{ ev.postulacion?.convocatoria?.codigo ?? '—' }}</td>
                <td>
                  <span v-if="ev.puntaje_total" class="font-semibold" style="color:var(--clr-primary-700)">
                    {{ ev.puntaje_total }}
                  </span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td>
                  <span class="badge" :class="estadoBadge[ev.estado] || 'badge-gray'">{{ ev.estado }}</span>
                </td>
                <td>
                  <RouterLink :to="`/evaluaciones/${ev.id}`" class="btn btn-ghost btn-sm">
                    Calificar →
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
