<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route  = useRoute()
const router = useRouter()
const id     = route.params.id

const evaluacion  = ref<any>(null)
const desglose    = ref<any>(null)
const loading     = ref(true)
const calculando  = ref(false)
const cerrando    = ref(false)
const error       = ref('')

// Modal validar evidencia
const showValidarModal   = ref(false)
const evidenciaAValidar  = ref<any>(null)
const validarForm        = ref({ estado: '', comentario_postulacion: '' })
const validando          = ref(false)
const validarError       = ref('')

const estadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', completada: 'badge-blue', cerrada: 'badge-green',
}
const evidEstado: Record<string, string> = {
  pendiente: 'badge-yellow', aprobada: 'badge-green',
  observada: 'badge-indigo', rechazada: 'badge-red',
}
const vigenciaBadge = (ev: any) => {
  if (ev.vigente === true)  return 'badge-green'
  if (ev.vigente === false) return 'badge-red'
  return 'badge-gray'
}
const vigenciaLabel = (ev: any) => {
  if (ev.vigente === true)  return 'Vigente'
  if (ev.vigente === false) return `Vencida (${ev.fecha_vencimiento ?? ''})`
  return 'Sin fecha'
}

onMounted(cargar)

async function cargar() {
  loading.value = true
  error.value   = ''
  try {
    const { data } = await api.get(`/evaluaciones/${id}`)
    evaluacion.value = data
    if (data.puntaje_total) {
      const dRes = await api.get(`/evaluaciones/${id}/desglose`)
      desglose.value = dRes.data
    }
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al cargar la evaluación.'
  } finally {
    loading.value = false
  }
}

async function calcular() {
  calculando.value = true
  error.value = ''
  try {
    await api.post(`/evaluaciones/${id}/calcular`)
    await cargar()
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al calcular.'
  } finally {
    calculando.value = false
  }
}

async function cerrar() {
  if (!confirm('¿Cerrar la evaluación? Esta acción es irreversible.')) return
  cerrando.value = true
  error.value    = ''
  try {
    await api.post(`/evaluaciones/${id}/cerrar`)
    await cargar()
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al cerrar.'
  } finally {
    cerrando.value = false
  }
}

// ── Validar evidencia ────────────────────────────────────────────────────────
function abrirValidar(pivote: any) {
  evidenciaAValidar.value  = pivote
  validarForm.value        = { estado: pivote.estado_en_postulacion, comentario_postulacion: pivote.comentario_postulacion ?? '' }
  validarError.value       = ''
  showValidarModal.value   = true
}

async function guardarValidacion() {
  if (!validarForm.value.estado) return
  validarError.value = ''
  validando.value    = true
  try {
    await api.patch(`/evidencias/${evidenciaAValidar.value.evidencia_id}/validacion`, {
      postulacion_id:           evaluacion.value.postulacion_id,
      estado:                   validarForm.value.estado,
      comentario_postulacion:   validarForm.value.comentario_postulacion,
    })
    showValidarModal.value = false
    await cargar()
  } catch (e: any) {
    validarError.value = e.response?.data?.message || 'Error al guardar la validación.'
  } finally {
    validando.value = false
  }
}

// Evidencias del pivote (nueva estructura)
const pivotes = () => evaluacion.value?.postulacion?.postulacion_evidencias ?? []
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

  <div v-else-if="error" class="alert alert-error" style="margin:2rem">{{ error }}</div>

  <div v-else-if="evaluacion">
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm mb-1" @click="router.back()">← Volver</button>
        <h1>Evaluación #{{ evaluacion.id }}</h1>
        <p>
          {{ evaluacion.postulacion?.postulante?.name }}
          — {{ evaluacion.postulacion?.plaza?.asignatura }}
        </p>
      </div>
      <div class="flex gap-2">
        <span class="badge" :class="estadoBadge[evaluacion.estado] || 'badge-gray'">
          {{ evaluacion.estado }}
        </span>
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid mb-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;font-size:1.5rem">🏆</div>
        <div>
          <div class="stat-value" style="color:var(--clr-primary-700)">
            {{ evaluacion.puntaje_total ?? '—' }}
          </div>
          <div class="stat-label">Puntaje total</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;font-size:1.5rem">📎</div>
        <div>
          <div class="stat-value">{{ pivotes().length }}</div>
          <div class="stat-label">Documentos en expediente</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb;font-size:1.5rem">✅</div>
        <div>
          <div class="stat-value">
            {{ pivotes().filter((p: any) => p.estado_en_postulacion === 'aprobada').length }}
          </div>
          <div class="stat-label">Aprobados</div>
        </div>
      </div>
    </div>

    <!-- Documentos del expediente -->
    <div class="card mb-4" style="padding:0">
      <div class="card-header" style="padding:1rem 1.25rem">
        <h3 class="card-title">Documentos del expediente</h3>
        <span class="text-sm text-muted">
          {{ evaluacion.postulacion?.convocatoria?.codigo }}
        </span>
      </div>

      <div v-if="pivotes().length === 0">
        <div class="empty-state">
          <h3>Sin documentos</h3>
          <p>El postulante no ha cargado ningún documento en su expediente.</p>
        </div>
      </div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Variable</th>
              <th>Archivo</th>
              <th>Emisión</th>
              <th>Vigencia</th>
              <th>Estado</th>
              <th>Observación</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="piv in pivotes()" :key="piv.id">
              <td class="text-sm">{{ piv.evidencia?.variable?.nombre ?? '—' }}</td>
              <td class="font-medium text-sm">{{ piv.evidencia?.nombre_original }}</td>
              <td class="text-sm text-muted">{{ piv.evidencia?.fecha_emision ?? '—' }}</td>
              <td>
                <span class="badge" :class="vigenciaBadge(piv)">{{ vigenciaLabel(piv) }}</span>
              </td>
              <td>
                <span class="badge" :class="evidEstado[piv.estado_en_postulacion] || 'badge-gray'">
                  {{ piv.estado_en_postulacion }}
                </span>
              </td>
              <td class="text-sm text-muted" style="max-width:180px">
                {{ piv.comentario_postulacion || '—' }}
              </td>
              <td>
                <button
                  v-if="evaluacion.estado !== 'cerrada'"
                  class="btn btn-ghost btn-sm"
                  @click="abrirValidar(piv)"
                >
                  Validar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Desglose por sub-rubro -->
    <div v-if="desglose" class="card mb-4">
      <div class="card-header">
        <h3 class="card-title">Desglose por sub-rubro</h3>
        <span class="badge badge-blue">{{ desglose.tabla_nombre }}</span>
      </div>
      <div v-for="rubro in desglose.rubros" :key="rubro.nombre" class="mb-4" style="padding:0 1.25rem 1rem">
        <div class="flex justify-between items-center mb-2">
          <h4 class="font-medium">{{ rubro.nombre }}</h4>
          <div class="flex items-center gap-2">
            <span v-if="rubro.tope_aplicado" class="badge badge-yellow">Tope aplicado</span>
            <span class="font-semibold" style="color:var(--clr-primary-700)">
              {{ rubro.puntaje_final }} / {{ rubro.puntaje_max }} pts
            </span>
          </div>
        </div>
        <div class="progress-bar mb-3">
          <div
            class="progress-fill"
            :style="{ width: (rubro.puntaje_final / rubro.puntaje_max * 100) + '%' }"
          ></div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Variable</th><th>Tipo</th><th>Bruto</th><th>Aplicado</th><th>Máx.</th></tr>
            </thead>
            <tbody>
              <tr v-for="v in rubro.variables" :key="v.variable_id">
                <td class="text-sm">{{ v.nombre }}</td>
                <td><span class="badge badge-gray" style="font-size:0.7rem">{{ v.tipo_calculo }}</span></td>
                <td class="text-sm">{{ v.puntaje_bruto }}</td>
                <td class="font-medium" style="color:var(--clr-primary-700)">{{ v.puntaje_aplicado }}</td>
                <td class="text-muted text-sm">{{ v.puntaje_max }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Acciones -->
    <div v-if="error" class="alert alert-error mb-3">{{ error }}</div>

    <div v-if="evaluacion.estado !== 'cerrada'" class="card">
      <div class="card-header"><h3 class="card-title">Acciones</h3></div>
      <div class="flex gap-3">
        <button class="btn btn-primary" :disabled="calculando" @click="calcular">
          <span v-if="calculando" class="spinner"></span>
          {{ calculando ? 'Calculando...' : '⚡ Calcular puntaje' }}
        </button>
        <button
          v-if="evaluacion.puntaje_total"
          class="btn btn-secondary"
          :disabled="cerrando"
          @click="cerrar"
        >
          <span v-if="cerrando" class="spinner"></span>
          {{ cerrando ? 'Cerrando...' : '🔒 Cerrar evaluación' }}
        </button>
      </div>
    </div>

    <div v-else class="alert alert-info">
      ✅ Evaluación cerrada el
      {{ new Date(evaluacion.cerrada_en).toLocaleDateString('es-PE') }}.
      Puntaje final: <strong>{{ evaluacion.puntaje_total }}</strong>
    </div>

    <!-- Modal validar evidencia -->
    <div v-if="showValidarModal" class="modal-overlay" @click.self="showValidarModal = false">
      <div class="modal">
        <div class="modal-header">
          <h2>Validar documento</h2>
          <button class="btn btn-ghost btn-icon" @click="showValidarModal = false">✕</button>
        </div>
        <div class="modal-body">
          <p class="text-sm text-muted mb-4">
            <strong>{{ evidenciaAValidar?.evidencia?.nombre_original }}</strong><br>
            Variable: {{ evidenciaAValidar?.evidencia?.variable?.nombre }}
          </p>

          <div v-if="validarError" class="alert alert-error mb-4">{{ validarError }}</div>

          <div class="form-group mb-4">
            <label class="form-label">Resultado <span class="required">*</span></label>
            <div class="flex gap-3">
              <label
                v-for="opt in [['aprobada','✅ Aprobada','badge-green'], ['observada','⚠️ Observada','badge-indigo'], ['rechazada','❌ Rechazada','badge-red']]"
                :key="opt[0]"
                class="flex items-center gap-2"
                style="cursor:pointer"
              >
                <input type="radio" v-model="validarForm.estado" :value="opt[0]" />
                <span class="badge" :class="opt[2]">{{ opt[1] }}</span>
              </label>
            </div>
          </div>

          <div
            v-if="validarForm.estado === 'observada' || validarForm.estado === 'rechazada'"
            class="form-group"
          >
            <label class="form-label">
              Comentario <span class="required">*</span>
            </label>
            <textarea
              v-model="validarForm.comentario_postulacion"
              class="form-control"
              rows="3"
              placeholder="Indica el motivo de la observación o rechazo..."
            ></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showValidarModal = false">Cancelar</button>
          <button
            class="btn btn-primary"
            :disabled="validando || !validarForm.estado"
            @click="guardarValidacion"
          >
            <span v-if="validando" class="spinner"></span>
            {{ validando ? 'Guardando...' : 'Guardar validación' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
