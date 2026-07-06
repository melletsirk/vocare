<script setup lang="ts">
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const logs    = ref<any[]>([])
const loading = ref(true)
const filtroEvento = ref('')
const filtroDesde  = ref('')

onMounted(cargar)

async function cargar() {
  loading.value = true
  const params: any = {}
  if (filtroEvento.value) params.evento = filtroEvento.value
  if (filtroDesde.value)  params.desde  = filtroDesde.value
  const { data } = await api.get('/auditoria', { params })
  logs.value = data.data ?? data
  loading.value = false
}

const eventColor: Record<string, string> = {
  'auth.login': 'badge-green', 'auth.logout': 'badge-gray',
  'usuario.creado': 'badge-blue', 'usuario.desactivado': 'badge-red',
  'evaluacion.cerrada': 'badge-green', 'resultados.publicados': 'badge-indigo',
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1>Auditoría</h1>
    </div>

    <div class="card mb-4" style="padding:1rem">
      <div class="flex gap-3 items-center" style="flex-wrap:wrap">
        <input v-model="filtroEvento" class="form-control" style="max-width:220px" placeholder="Filtrar por evento..." @input="cargar" />
        <input v-model="filtroDesde" type="date" class="form-control" style="max-width:160px" @change="cargar" />
      </div>
    </div>

    <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

    <div v-else class="card" style="padding:0">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Fecha</th><th>Usuario</th><th>Evento</th><th>IP</th></tr>
          </thead>
          <tbody>
            <tr v-for="log in logs" :key="log.id">
              <td class="text-sm text-muted" style="white-space:nowrap">
                {{ new Date(log.created_at).toLocaleString('es-PE') }}
              </td>
              <td class="text-sm">{{ log.user?.name ?? 'Sistema' }}</td>
              <td>
                <span class="badge" :class="eventColor[log.event] ?? 'badge-gray'">{{ log.event }}</span>
              </td>
              <td class="text-xs text-muted">{{ log.ip_address ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
