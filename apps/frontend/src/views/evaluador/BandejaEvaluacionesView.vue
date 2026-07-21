<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'

const router      = useRouter()
const evaluaciones = ref<any[]>([])
const loading      = ref(true)
const error        = ref('')

const estadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', completada: 'badge-blue', cerrada: 'badge-green',
}

onMounted(async () => {
  try {
    // GET /evaluaciones devuelve las evaluaciones asignadas al usuario (evaluador/admin)
    // con postulacion.plaza, postulacion.convocatoria y postulacion.user eager-loaded
    const { data } = await api.get('/evaluaciones')
    evaluaciones.value = data.data ?? data
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al cargar evaluaciones.'
  } finally {
    loading.value = false
  }
})
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

    <div v-else-if="evaluaciones.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin evaluaciones asignadas</h3>
        <p>No tienes expedientes pendientes de calificar en este momento.</p>
      </div>
    </div>

    <div v-else class="card" style="padding:0">
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
  </div>
</template>
