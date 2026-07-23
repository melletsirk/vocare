<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import Icon from '@/components/ui/Icon.vue'

const router    = useRouter()
const authStore = useAuthStore()

const postulaciones = ref<any[]>([])
const loading       = ref(true)

// Modal Nueva Postulación
const showModal      = ref(false)
const savingModal    = ref(false)
const convocatorias  = ref<any[]>([])
const plazas         = ref<any[]>([])
const form           = ref({ convocatoria_id: '', plaza_id: '', categoria_actual: '' })

const estadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', observada: 'badge-indigo',
  rechazada: 'badge-red', aprobada_etapa: 'badge-green', ganadora: 'badge-blue',
}
const estadoLabel: Record<string, string> = {
  en_proceso: 'En proceso', observada: 'Observada',
  rechazada: 'Rechazada', aprobada_etapa: 'Aprobada', ganadora: 'Ganadora',
}

async function fetchPostulaciones() {
  const { data } = await api.get('/postulaciones')
  postulaciones.value = data.data ?? data
}

onMounted(async () => {
  await fetchPostulaciones()
  loading.value = false
})

async function openModal() {
  form.value  = { convocatoria_id: '', plaza_id: '', categoria_actual: '' }
  plazas.value = []
  showModal.value = true
  try {
    const { data } = await api.get('/convocatorias')
    // Filtrar en cliente: solo publicadas o en_proceso
    const activas = (data.data ?? data).filter(
      (c: any) => ['publicada', 'en_proceso'].includes(c.estado)
    )
    convocatorias.value = activas
  } catch (e) {
    console.error(e)
  }
}

async function onConvocatoriaChange() {
  form.value.plaza_id = ''
  if (!form.value.convocatoria_id) { plazas.value = []; return }
  const { data } = await api.get(`/convocatorias/${form.value.convocatoria_id}/plazas`)
  plazas.value = data.data ?? data
}

async function submitPostulacion() {
  if (!form.value.plaza_id) return
  savingModal.value = true
  try {
    await api.post('/postulaciones', form.value)
    showModal.value = false
    loading.value   = true
    await fetchPostulaciones()
  } catch (e: any) {
    alert(e.response?.data?.message || 'Error al crear la postulación')
  } finally {
    savingModal.value = false
    loading.value     = false
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Mis Postulaciones</h1>
        <p>Historial de tus procesos de selección</p>
      </div>
      <button v-if="authStore.isPostulante" class="btn btn-primary" @click="openModal">
        + Nueva postulación
      </button>
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
              <td class="text-sm">
                <span v-if="p.fecha_envio">
                  {{ new Date(p.fecha_envio).toLocaleDateString('es-PE') }}
                </span>
                <span v-else class="badge badge-gray" style="font-size:0.7rem">Borrador</span>
              </td>
              <td>
                <span class="badge" :class="estadoBadge[p.estado] || 'badge-gray'">
                  <Icon v-if="p.estado === 'ganadora'" name="award" :size="12" />
                  {{ estadoLabel[p.estado] ?? p.estado }}
                </span>
              </td>
              <td>
                <RouterLink :to="`/mis-postulaciones/${p.id}`" class="btn btn-ghost btn-sm">
                  Ver detalle →
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Nueva Postulación -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal">
        <div class="modal-header">
          <h2>Nueva postulación</h2>
          <button class="btn btn-ghost btn-icon" @click="showModal = false"><Icon name="x" :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="form-group mb-4">
            <label class="form-label">Convocatoria <span class="required">*</span></label>
            <select v-model="form.convocatoria_id" class="form-control" @change="onConvocatoriaChange">
              <option value="">Seleccionar convocatoria...</option>
              <option v-for="c in convocatorias" :key="c.id" :value="c.id">
                {{ c.nombre }} ({{ c.codigo }})
              </option>
            </select>
          </div>
          <div class="form-group mb-4">
            <label class="form-label">Plaza <span class="required">*</span></label>
            <select v-model="form.plaza_id" class="form-control" :disabled="!plazas.length">
              <option value="">
                {{ form.convocatoria_id ? 'Seleccionar plaza...' : 'Elige una convocatoria primero' }}
              </option>
              <option v-for="p in plazas" :key="p.id" :value="p.id">
                {{ p.asignatura }} — {{ p.departamento }}
              </option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Categoría actual (opcional)</label>
            <input
              v-model="form.categoria_actual"
              type="text"
              class="form-control"
              placeholder="Ej: Auxiliar, Asociado..."
            />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showModal = false">Cancelar</button>
          <button
            class="btn btn-primary"
            :disabled="savingModal || !form.plaza_id"
            @click="submitPostulacion"
          >
            <span v-if="savingModal" class="spinner"></span>
            {{ savingModal ? 'Creando...' : 'Crear postulación' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
