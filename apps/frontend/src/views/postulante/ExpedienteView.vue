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

const fileInput    = ref<HTMLInputElement | null>(null)
const selectedVar  = ref<string>('')
const fechaEmision = ref<string>('')

// Modal Reutilizar
const showReutilizarModal  = ref(false)
const misEvidencias        = ref<any[]>([])
const loadingEvidencias    = ref(false)

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

async function abrirReutilizar() {
  showReutilizarModal.value = true
  loadingEvidencias.value   = true
  try {
    const { data } = await api.get('/me/evidencias')
    const asociadasIds = evidencias.value.map((e: any) => e.evidencia_id)
    misEvidencias.value = data.filter((e: any) => !asociadasIds.includes(e.id))
  } catch (e) {
    console.error(e)
  } finally {
    loadingEvidencias.value = false
  }
}

async function reutilizarEvidencia(evidenciaId: number) {
  try {
    await api.post(`/postulaciones/${id}/evidencias/reutilizar`, { evidencia_id: evidenciaId })
    showReutilizarModal.value = false
    await cargar()
  } catch (e: any) {
    alert(e.response?.data?.message || 'Error al reutilizar evidencia')
  }
}

function formatBytes(b: number) {
  if (b < 1024) return b + ' B'
  if (b < 1048576) return (b / 1024).toFixed(1) + ' KB'
  return (b / 1048576).toFixed(1) + ' MB'
}

// Solo cuenta archivos únicos (las evidencias reutilizadas no ocupan espacio nuevo)
const totalBytes = () => {
  const unicas = new Map<number, number>()
  evidencias.value.forEach((ev: any) => {
    if (ev.evidencia) unicas.set(ev.evidencia_id, ev.evidencia.tamano_bytes)
  })
  let sum = 0
  unicas.forEach(val => { sum += val })
  return sum
}
const pctUsed = () => Math.min((totalBytes() / 209715200) * 100, 100).toFixed(1)
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
        <span class="text-sm font-medium">Almacenamiento usado (archivos únicos)</span>
        <span class="text-sm text-muted">{{ formatBytes(totalBytes()) }} / 200 MB</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" :style="{ width: pctUsed() + '%' }"></div>
      </div>
      <div class="text-xs text-muted mt-1">{{ pctUsed() }}% utilizado</div>
    </div>

    <div class="grid-2 gap-3 mb-4">
      <!-- Subir evidencia nueva -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Subir documento nuevo</h3>
        </div>
        <div v-if="uploadError" class="alert alert-error mb-4">{{ uploadError }}</div>

        <div class="form-group mb-3">
          <label class="form-label">Variable / Indicador <span class="required">*</span></label>
          <select v-model="selectedVar" class="form-control">
            <option value="">Seleccionar variable...</option>
            <optgroup
              v-for="rubro in postulacion?.convocatoria?.tabla_snapshot?.rubros"
              :label="rubro.nombre"
            >
              <option v-for="v in rubro.variables" :key="v.id" :value="v.id">{{ v.nombre }}</option>
            </optgroup>
          </select>
        </div>
        <div class="form-group mb-3">
          <label class="form-label">Fecha de emisión del documento</label>
          <input v-model="fechaEmision" type="date" class="form-control" />
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

      <!-- Reutilizar evidencia existente -->
      <div class="card" style="display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;min-height:200px">
        <h3 class="card-title mb-2">Reutilizar documento existente</h3>
        <p class="text-sm text-muted mb-4">
          ¿Ya subiste este archivo en otra postulación? Puedes reutilizarlo sin
          consumir espacio adicional ni requerir nueva validación.
        </p>
        <button class="btn btn-secondary" @click="abrirReutilizar">
          Explorar mis documentos
        </button>
      </div>
    </div>

    <!-- Lista de evidencias asociadas -->
    <div class="card" style="padding:0">
      <div class="card-header" style="padding:1rem 1.25rem">
        <h3 class="card-title">Documentos en este expediente ({{ evidencias.length }})</h3>
      </div>

      <div v-if="evidencias.length === 0">
        <div class="empty-state">
          <h3>Sin documentos</h3>
          <p>Aún no has cargado ningún documento a este expediente.</p>
        </div>
      </div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Variable</th>
              <th>Archivo</th>
              <th>Tamaño</th>
              <th>Emisión</th>
              <th>Vigencia</th>
              <th>Estado</th>
              <th>Observación</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ev in evidencias" :key="ev.id">
              <td class="text-sm">{{ ev.evidencia?.variable?.nombre ?? '—' }}</td>
              <td>
                <span class="font-medium text-sm">{{ ev.evidencia?.nombre_original }}</span>
              </td>
              <td class="text-sm text-muted">{{ formatBytes(ev.evidencia?.tamano_bytes || 0) }}</td>
              <td class="text-sm text-muted">{{ ev.evidencia?.fecha_emision ?? '—' }}</td>
              <td>
                <span v-if="ev.vigente === true"  class="badge badge-green">Vigente</span>
                <span v-else-if="ev.vigente === false" class="badge badge-red">
                  Vencida<br><span class="text-xs">({{ ev.fecha_vencimiento }})</span>
                </span>
                <span v-else class="badge badge-gray">Sin fecha</span>
              </td>
              <td>
                <span class="badge" :class="estadoEvidencia[ev.estado_en_postulacion]">
                  {{ ev.estado_en_postulacion }}
                </span>
              </td>
              <td class="text-sm" style="max-width:200px">
                <span
                  v-if="ev.estado_en_postulacion === 'observada' && ev.comentario_postulacion"
                  class="text-muted"
                >{{ ev.comentario_postulacion }}</span>
                <span v-else class="text-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Reutilizar -->
    <div v-if="showReutilizarModal" class="modal-overlay" @click.self="showReutilizarModal = false">
      <div class="modal" style="max-width:800px;width:100%">
        <div class="modal-header">
          <h3 class="card-title">Reutilizar documento existente</h3>
          <button class="btn btn-ghost btn-icon" @click="showReutilizarModal = false">✕</button>
        </div>
        <div class="modal-body" style="max-height:60vh;overflow-y:auto">
          <div v-if="loadingEvidencias" class="loading-center">
            <span class="spinner"></span> Cargando tus documentos...
          </div>
          <div v-else-if="misEvidencias.length === 0" class="empty-state">
            <h3>Sin documentos disponibles</h3>
            <p>No tienes documentos reutilizables o todos ya están asociados a esta postulación.</p>
          </div>
          <div v-else class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Archivo</th>
                  <th>Variable original</th>
                  <th>Fecha emisión</th>
                  <th>Estado</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in misEvidencias" :key="m.id">
                  <td class="font-medium text-sm">{{ m.nombre_original }}</td>
                  <td class="text-sm text-muted">{{ m.variable?.nombre ?? '—' }}</td>
                  <td class="text-sm text-muted">{{ m.fecha_emision || '—' }}</td>
                  <td>
                    <span class="badge" :class="estadoEvidencia[m.estado] || 'badge-gray'">
                      {{ m.estado }}
                    </span>
                  </td>
                  <td>
                    <button class="btn btn-primary btn-sm" @click="reutilizarEvidencia(m.id)">
                      Reutilizar
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
