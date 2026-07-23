<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import Icon from '@/components/ui/Icon.vue'
import Stepper from '@/components/ui/Stepper.vue'
import { construirChecklist, type ChecklistResumen } from '@/utils/expedienteChecklist'
import { pasoActualDe, pasosConEstado } from '@/utils/postulacionProgreso'

const authStore = useAuthStore()

const postulaciones = ref<any[]>([])
const loading        = ref(true)

// Progreso del expediente (solo postulaciones aún no enviadas) y resultado
// publicado (solo enviadas) — el índice de listado no trae ninguno de los
// dos, se completan aparte por postulación.
const checklistPorPostulacion = reactive<Record<number, ChecklistResumen>>({})
const resultadoPorPostulacion = reactive<Record<number, any>>({})

// Modal Nueva Postulación
const showModal      = ref(false)
const savingModal    = ref(false)
const convocatorias  = ref<any[]>([])
const plazas         = ref<any[]>([])
const form           = ref({ convocatoria_id: '', plaza_id: '', categoria_actual: '' })

onMounted(async () => {
  await fetchPostulaciones()
  loading.value = false
})

async function fetchPostulaciones() {
  const { data } = await api.get('/postulaciones')
  postulaciones.value = data.data ?? data

  await Promise.all(postulaciones.value.map(async (p: any) => {
    if (!p.fecha_envio) {
      const { data: evidencias } = await api.get(`/postulaciones/${p.id}/evidencias`)
      checklistPorPostulacion[p.id] = construirChecklist(p.convocatoria?.tabla_snapshot, evidencias)
    } else {
      try {
        const { data: resultado } = await api.get(`/postulaciones/${p.id}/resultado`)
        resultadoPorPostulacion[p.id] = resultado
      } catch {
        resultadoPorPostulacion[p.id] = null
      }
    }
  }))
}

const resultadoLabel: Record<string, string> = {
  ganador: 'Resultado: Ganador/a', reserva: 'Resultado: Reserva',
  no_ganador: 'Resultado: No ganador/a', desierta: 'Plaza declarada desierta',
}

function siguientePaso(p: any): string {
  if (!p.fecha_envio) {
    const c = checklistPorPostulacion[p.id]
    if (c && c.totalCompletos < c.totalRequisitos) {
      return `Sube ${c.totalRequisitos - c.totalCompletos} documento(s) pendiente(s) y envía tu postulación`
    }
    return 'Todo listo — envía tu postulación formalmente'
  }
  if (p.estado === 'rechazada') return 'Esta postulación fue rechazada'
  if (p.estado === 'observada') return 'Tienes una observación pendiente de corregir'
  const resultado = resultadoPorPostulacion[p.id]
  if (resultado) return resultadoLabel[resultado.estado] ?? 'Resultado publicado'
  return 'Tu expediente está en evaluación'
}

function ctaTo(p: any): string {
  if (!p.fecha_envio) return `/mis-postulaciones/${p.id}/expediente`
  return `/mis-postulaciones/${p.id}`
}

function ctaLabel(p: any): string {
  if (!p.fecha_envio) return 'Ir al expediente →'
  if (resultadoPorPostulacion[p.id]) return 'Ver resultado →'
  return 'Ver detalle →'
}

function pct(p: any): number {
  const c = checklistPorPostulacion[p.id]
  if (!c || c.totalRequisitos === 0) return 0
  return Math.round((c.totalCompletos / c.totalRequisitos) * 100)
}

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
        <h1>Mis postulaciones</h1>
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

    <div v-else>
      <div v-for="p in postulaciones" :key="p.id" class="card postulacion-card">
        <div class="postulacion-card-head">
          <div>
            <h3>{{ p.plaza?.asignatura }}</h3>
            <p class="text-xs text-muted">{{ p.convocatoria?.codigo }} · {{ p.plaza?.facultad }}</p>
          </div>
          <span v-if="p.estado === 'observada'" class="badge badge-indigo">Observada</span>
          <span v-else-if="p.estado === 'rechazada'" class="badge badge-red">Rechazada</span>
          <span v-else-if="resultadoPorPostulacion[p.id]?.estado === 'ganador'" class="badge badge-green">
            <Icon name="award" :size="12" /> Ganador/a
          </span>
        </div>

        <Stepper :steps="pasosConEstado(p)" :current-key="pasoActualDe(p, !!resultadoPorPostulacion[p.id])" class="postulacion-card-stepper" />

        <template v-if="!p.fecha_envio && checklistPorPostulacion[p.id]">
          <div class="flex justify-between items-center mb-1">
            <span class="text-xs text-muted">
              {{ checklistPorPostulacion[p.id].totalCompletos }} / {{ checklistPorPostulacion[p.id].totalRequisitos }} requisitos completos
            </span>
          </div>
          <div class="progress-bar mb-3"><div class="progress-fill" :style="{ width: pct(p) + '%' }"></div></div>
        </template>

        <div class="postulacion-card-foot">
          <p class="text-sm">{{ siguientePaso(p) }}</p>
          <RouterLink :to="ctaTo(p)" class="btn btn-secondary btn-sm">{{ ctaLabel(p) }}</RouterLink>
        </div>
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
