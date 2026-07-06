<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const auth   = useAuthStore()
const router = useRouter()

const convocatorias = ref<any[]>([])
const loading       = ref(true)
const filtroEstado  = ref('')

const estadoLabel: Record<string, string> = {
  borrador: 'Borrador', publicada: 'Publicada',
  en_proceso: 'En Proceso', cerrada: 'Cerrada', desierta: 'Desierta',
}
const estadoBadge: Record<string, string> = {
  borrador: 'badge-gray', publicada: 'badge-blue',
  en_proceso: 'badge-yellow', cerrada: 'badge-green', desierta: 'badge-red',
}

onMounted(async () => {
  await cargar()
})

async function cargar() {
  loading.value = true
  try {
    const params: any = {}
    if (filtroEstado.value) params.estado = filtroEstado.value
    const { data } = await api.get('/convocatorias', { params })
    convocatorias.value = data.data ?? data
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Convocatorias</h1>
        <p>Procesos de selección y contratación docente</p>
      </div>
      <RouterLink
        v-if="auth.isAdmin || auth.rol === 'admin_convocatoria'"
        to="/convocatorias/nueva"
        class="btn btn-primary"
      >
        + Nueva convocatoria
      </RouterLink>
    </div>

    <!-- Filtros -->
    <div class="card mb-4" style="padding:1rem">
      <div class="flex gap-3 items-center">
        <label class="form-label" style="white-space:nowrap;margin:0">Estado:</label>
        <select v-model="filtroEstado" class="form-control" style="max-width:200px" @change="cargar">
          <option value="">Todos</option>
          <option v-for="(label, val) in estadoLabel" :key="val" :value="val">{{ label }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading-center">
      <span class="spinner"></span> Cargando convocatorias...
    </div>

    <div v-else-if="convocatorias.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin convocatorias</h3>
        <p>No hay convocatorias que coincidan con los filtros seleccionados.</p>
      </div>
    </div>

    <div v-else class="card" style="padding:0">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Tipo de Proceso</th>
              <th>Plazas</th>
              <th>Vigencia</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="conv in convocatorias" :key="conv.id">
              <td><span class="font-medium">{{ conv.codigo }}</span></td>
              <td>{{ conv.nombre }}</td>
              <td>{{ conv.tipo_proceso }}</td>
              <td>{{ conv.plazas_count ?? '—' }}</td>
              <td class="text-sm text-muted">
                {{ new Date(conv.fecha_inicio).toLocaleDateString('es-PE') }} →
                {{ new Date(conv.fecha_fin).toLocaleDateString('es-PE') }}
              </td>
              <td>
                <span class="badge" :class="estadoBadge[conv.estado]">
                  {{ estadoLabel[conv.estado] }}
                </span>
              </td>
              <td>
                <RouterLink :to="`/convocatorias/${conv.id}`" class="btn btn-ghost btn-sm">
                  Ver →
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
