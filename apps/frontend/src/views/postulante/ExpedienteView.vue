<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route  = useRoute()
const router = useRouter()
const id     = route.params.id  // postulacion id

const postulacion = ref<any>(null)
const evidencias  = ref<any[]>([])
const loading     = ref(true)
const uploading   = ref(false)
const uploadError = ref('')

const fileInput   = ref<HTMLInputElement | null>(null)
const selectedVar = ref<string>('')
const fechaEmision = ref<string>('')

onMounted(cargar)

async function cargar() {
  loading.value = true
  try {
    const [pRes, eRes] = await Promise.all([
      api.get(`/postulaciones/${id}`),
      api.get(`/postulaciones/${id}/evidencias`),
    ])
    postulacion.value = pRes.data
    evidencias.value  = eRes.data
  } finally {
    loading.value = false
  }
}

const variables = () =>
  postulacion.value?.convocatoria?.tabla_snapshot?.rubros
    ?.flatMap((r: any) => r.variables) ?? []

const estadoEvidencia: Record<string, string> = {
  pendiente: 'badge-yellow', aprobada: 'badge-green',
  observada: 'badge-indigo', rechazada: 'badge-red',
}

async function subirEvidencia() {
  if (!fileInput.value?.files?.[0] || !selectedVar.value) {
    uploadError.value = 'Selecciona un archivo y la variable correspondiente.'
    return
  }
  uploadError.value = ''
  uploading.value   = true
  const formData = new FormData()
  formData.append('archivo', fileInput.value.files[0])
  formData.append('variable_id', selectedVar.value)
  if (fechaEmision.value) formData.append('fecha_emision', fechaEmision.value)

  try {
    await api.post(`/postulaciones/${id}/evidencias`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    selectedVar.value  = ''
    fechaEmision.value = ''
    if (fileInput.value) fileInput.value.value = ''
    await cargar()
  } catch (e: any) {
    uploadError.value = e.response?.data?.message || 'Error al subir el archivo.'
  } finally {
    uploading.value = false
  }
}

function formatBytes(b: number) {
  if (b < 1024) return b + ' B'
  if (b < 1048576) return (b / 1024).toFixed(1) + ' KB'
  return (b / 1048576).toFixed(1) + ' MB'
}

const totalBytes  = () => evidencias.value.reduce((s, e) => s + e.tamano_bytes, 0)
const pctUsed     = () => Math.min((totalBytes() / 209715200) * 100, 100).toFixed(1)
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

  <div v-else-if="postulacion">
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm mb-1" @click="router.back()">← Volver</button>
        <h1>Expediente Digital</h1>
        <p>{{ postulacion.plaza?.asignatura }} — {{ postulacion.convocatoria?.nombre }}</p>
      </div>
      <span class="badge badge-blue">{{ postulacion.estado?.replace('_', ' ') }}</span>
    </div>

    <!-- Cuota storage -->
    <div class="card mb-4">
      <div class="flex justify-between items-center mb-2">
        <span class="text-sm font-medium">Almacenamiento usado</span>
        <span class="text-sm text-muted">{{ formatBytes(totalBytes()) }} / 200 MB</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" :style="{ width: pctUsed() + '%' }"></div>
      </div>
      <div class="text-xs text-muted mt-1">{{ pctUsed() }}% utilizado</div>
    </div>

    <!-- Subir evidencia -->
    <div class="card mb-4">
      <div class="card-header">
        <h3 class="card-title">Subir documento</h3>
      </div>
      <div v-if="uploadError" class="alert alert-error mb-4">{{ uploadError }}</div>

      <div class="grid-2 mb-4">
        <div class="form-group">
          <label class="form-label">Variable / Indicador <span class="required">*</span></label>
          <select v-model="selectedVar" class="form-control">
            <option value="">Seleccionar variable...</option>
            <optgroup v-for="rubro in postulacion?.convocatoria?.tabla_snapshot?.rubros" :label="rubro.nombre">
              <option v-for="v in rubro.variables" :key="v.id" :value="v.id">{{ v.nombre }}</option>
            </optgroup>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Fecha de emisión del documento</label>
          <input v-model="fechaEmision" type="date" class="form-control" />
        </div>
      </div>

      <div class="form-group mb-4">
        <label class="form-label">Archivo <span class="required">*</span></label>
        <input ref="fileInput" type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" />
        <span class="form-hint">PDF, JPG o PNG — máximo 10 MB por archivo</span>
      </div>

      <button class="btn btn-primary" :disabled="uploading" @click="subirEvidencia">
        <span v-if="uploading" class="spinner"></span>
        {{ uploading ? 'Subiendo...' : 'Subir documento' }}
      </button>
    </div>

    <!-- Lista de evidencias -->
    <div class="card" style="padding:0">
      <div class="card-header" style="padding:1rem 1.25rem">
        <h3 class="card-title">Documentos cargados ({{ evidencias.length }})</h3>
      </div>

      <div v-if="evidencias.length === 0">
        <div class="empty-state">
          <h3>Sin documentos</h3>
          <p>Aún no has subido ningún documento a tu expediente.</p>
        </div>
      </div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr><th>Variable</th><th>Archivo</th><th>Tamaño</th><th>Fecha emisión</th><th>Estado</th><th>Observación</th></tr>
          </thead>
          <tbody>
            <tr v-for="ev in evidencias" :key="ev.id">
              <td class="text-sm">{{ ev.variable?.nombre ?? '—' }}</td>
              <td>
                <span class="font-medium text-sm">{{ ev.nombre_original }}</span>
              </td>
              <td class="text-sm text-muted">{{ formatBytes(ev.tamano_bytes) }}</td>
              <td class="text-sm text-muted">{{ ev.fecha_emision ?? '—' }}</td>
              <td>
                <span class="badge" :class="estadoEvidencia[ev.estado]">{{ ev.estado }}</span>
              </td>
              <td class="text-sm" style="max-width:200px">
                <span v-if="ev.comentario_observacion" class="text-muted">{{ ev.comentario_observacion }}</span>
                <span v-else class="text-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
