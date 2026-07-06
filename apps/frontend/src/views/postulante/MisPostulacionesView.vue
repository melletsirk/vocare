<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'

const router = useRouter()
const postulaciones = ref<any[]>([])
const loading       = ref(true)

const estadoBadge: Record<string, string> = {
  en_proceso:'badge-yellow', observada:'badge-indigo',
  rechazada:'badge-red', aprobada_etapa:'badge-green', ganadora:'badge-blue',
}
const estadoLabel: Record<string, string> = {
  en_proceso:'En proceso', observada:'Observada',
  rechazada:'Rechazada', aprobada_etapa:'Aprobada', ganadora:'🏆 Ganadora',
}

onMounted(async () => {
  const { data } = await api.get('/postulaciones')
  postulaciones.value = data.data ?? data
  loading.value = false
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Mis Postulaciones</h1>
        <p>Historial de tus procesos de selección</p>
      </div>
    </div>

    <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

    <div v-else-if="postulaciones.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin postulaciones</h3>
        <p>Aún no te has postulado a ninguna plaza. Revisa las convocatorias disponibles.</p>
        <RouterLink to="/convocatorias" class="btn btn-primary">Ver convocatorias</RouterLink>
      </div>
    </div>

    <div v-else class="card" style="padding:0">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Convocatoria</th>
              <th>Plaza</th>
              <th>Facultad</th>
              <th>Enviada</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in postulaciones" :key="p.id">
              <td class="text-sm">{{ p.convocatoria?.codigo }}</td>
              <td class="font-medium">{{ p.plaza?.asignatura }}</td>
              <td class="text-sm text-muted">{{ p.plaza?.facultad }}</td>
              <td class="text-sm text-muted">
                {{ p.fecha_envio ? new Date(p.fecha_envio).toLocaleDateString('es-PE') : 'No enviada' }}
              </td>
              <td>
                <span class="badge" :class="estadoBadge[p.estado]">
                  {{ estadoLabel[p.estado] ?? p.estado }}
                </span>
              </td>
              <td>
                <RouterLink :to="`/mis-postulaciones/${p.id}/expediente`" class="btn btn-ghost btn-sm">
                  Expediente →
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
