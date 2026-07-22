<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route  = useRoute()
const router = useRouter()
const id     = route.params.id  // postulacion id

const postulacion = ref<any>(null)
const evidencias  = ref<any[]>([])
const loading     = ref(true)

// ── Checklist: una fila por variable (requisito) configurado, agrupado
// por rubro — nunca un dropdown genérico donde el postulante deba adivinar
// a qué requisito corresponde cada archivo. ─────────────────────────────────
const checklist = computed(() => {
  const rubros = postulacion.value?.convocatoria?.tabla_snapshot?.rubros ?? []
  return rubros.map((rubro: any) => ({
    nombre: rubro.nombre,
    variables: rubro.variables.map((variable: any) => ({
      variable,
      evidencias: evidencias.value.filter((ev: any) => ev.evidencia?.variable_id === variable.id),
    })),
  }))
})

// Estado por-fila del formulario de subida/reutilización, indexado por variable_id
const formPorVariable = reactive<Record<number, {
  expandido: 'subir' | 'reutilizar' | null
  fechaEmision: string
  subiendo: boolean
  error: string
  misEvidencias: any[]
  cargandoReutilizables: boolean
}>>({})

function estadoDe(variableId: number) {
  if (!formPorVariable[variableId]) {
    formPorVariable[variableId] = {
      expandido: null, fechaEmision: '', subiendo: false, error: '',
      misEvidencias: [], cargandoReutilizables: false,
    }
  }
  return formPorVariable[variableId]
}

const fileInputs = ref<Record<number, HTMLInputElement | null>>({})

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
const estadoLabel: Record<string, string> = {
  pendiente: 'Subido — en revisión', aprobada: 'Aprobado',
  observada: 'Observado', rechazada: 'Rechazado',
}

function abrirSubir(variableId: number) {
  const f = estadoDe(variableId)
  f.expandido = f.expandido === 'subir' ? null : 'subir'
  f.error = ''
}

async function abrirReutilizar(variableId: number) {
  const f = estadoDe(variableId)
  f.expandido = f.expandido === 'reutilizar' ? null : 'reutilizar'
  if (f.expandido !== 'reutilizar') return

  f.cargandoReutilizables = true
  try {
    const { data } = await api.get('/me/evidencias')
    const asociadasIds = evidencias.value.map((e: any) => e.evidencia_id)
    // Solo documentos que ya corresponden a ESTE requisito — reutilizar no
    // reasigna un documento a un requisito distinto del que ya tenía.
    f.misEvidencias = data.filter((e: any) => e.variable_id === variableId && !asociadasIds.includes(e.id))
  } finally {
    f.cargandoReutilizables = false
  }
}

async function subirEvidencia(variableId: number) {
  const f = estadoDe(variableId)
  const input = fileInputs.value[variableId]

  if (!input?.files?.[0]) {
    f.error = 'Selecciona un archivo.'
    return
  }
  f.error = ''
  f.subiendo = true

  const formData = new FormData()
  formData.append('archivo', input.files[0])
  formData.append('variable_id', String(variableId))
  if (f.fechaEmision) formData.append('fecha_emision', f.fechaEmision)

  try {
    await api.post(`/postulaciones/${id}/evidencias`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    f.expandido = null
    f.fechaEmision = ''
    if (input) input.value = ''
    await cargar()
  } catch (e: any) {
    f.error = e.response?.data?.message || 'Error al subir el archivo.'
  } finally {
    f.subiendo = false
  }
}

async function reutilizarEvidencia(variableId: number, evidenciaId: number) {
  try {
    await api.post(`/postulaciones/${id}/evidencias/reutilizar`, { evidencia_id: evidenciaId })
    estadoDe(variableId).expandido = null
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
const totalBytes = computed(() => {
  const unicas = new Map<number, number>()
  evidencias.value.forEach((ev: any) => {
    if (ev.evidencia) unicas.set(ev.evidencia_id, ev.evidencia.tamano_bytes)
  })
  let sum = 0
  unicas.forEach(val => { sum += val })
  return sum
})
const pctUsed = computed(() => Math.min((totalBytes.value / 209715200) * 100, 100).toFixed(1))
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
        <span class="text-sm text-muted">{{ formatBytes(totalBytes) }} / 200 MB</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" :style="{ width: pctUsed + '%' }"></div>
      </div>
      <div class="text-xs text-muted mt-1">{{ pctUsed }}% utilizado</div>
    </div>

    <!-- ══ CHECKLIST por requisito configurado — nunca un dropdown genérico ══ -->
    <div v-for="rubro in checklist" :key="rubro.nombre" class="mb-4">
      <h3 class="font-semibold mb-2">{{ rubro.nombre }}</h3>

      <div v-for="item in rubro.variables" :key="item.variable.id" class="card mb-3">
        <!-- fuente='etapa' (Clase Magistral, etc.) — no requiere documento -->
        <div v-if="item.variable.fuente === 'etapa'" class="flex justify-between items-center">
          <div>
            <p class="font-medium">{{ item.variable.nombre }}</p>
            <p class="text-xs text-muted">Se evalúa mediante evento presencial — no requiere documento.</p>
          </div>
          <span class="badge badge-gray">ℹ️ Evento presencial</span>
        </div>

        <!-- Requisito basado en documento -->
        <template v-else>
          <div class="flex justify-between items-center mb-2">
            <div>
              <p class="font-medium">{{ item.variable.nombre }}</p>
              <p class="text-xs text-muted">
                tope {{ item.variable.puntaje_max }} pts
                <span v-if="item.variable.periodo_validez_anios"> · válido {{ item.variable.periodo_validez_anios }} años</span>
              </p>
            </div>
            <span v-if="item.evidencias.length === 0" class="badge badge-gray">Sin documentos</span>
          </div>

          <!-- Estado visible sin clic adicional, por cada documento subido -->
          <div v-for="ev in item.evidencias" :key="ev.id" class="flex justify-between items-center mb-2" style="padding:0.5rem;border:1px solid var(--surface-border);border-radius:6px">
            <div style="min-width:0">
              <p class="font-medium text-sm truncate">📄 {{ ev.evidencia?.nombre_original }}</p>
              <p class="text-xs text-muted">
                {{ formatBytes(ev.evidencia?.tamano_bytes || 0) }}
                <span v-if="ev.vigente === true"> · vigente</span>
                <span v-else-if="ev.vigente === false"> · vencida ({{ ev.fecha_vencimiento }})</span>
              </p>
            </div>
            <div class="text-right" style="flex-shrink:0">
              <span class="badge" :class="estadoEvidencia[ev.estado_en_postulacion]">{{ estadoLabel[ev.estado_en_postulacion] }}</span>
              <p v-if="ev.estado_en_postulacion === 'observada' && ev.comentario_postulacion" class="text-xs text-muted mt-1" style="max-width:220px">
                {{ ev.comentario_postulacion }}
              </p>
            </div>
          </div>

          <!-- Acciones — subir/reutilizar siempre saben a qué requisito pertenecen -->
          <div class="flex gap-2 mt-2">
            <button class="btn btn-ghost btn-sm" @click="abrirSubir(item.variable.id)">
              {{ item.evidencias.length ? '+ Agregar otro documento' : '+ Subir documento' }}
            </button>
            <button class="btn btn-ghost btn-sm" @click="abrirReutilizar(item.variable.id)">
              ↺ Reutilizar existente
            </button>
          </div>

          <!-- Subir — inline, sin selector de variable -->
          <div v-if="estadoDe(item.variable.id).expandido === 'subir'" class="mt-3" style="padding-top:0.75rem;border-top:1px solid var(--surface-border)">
            <div v-if="estadoDe(item.variable.id).error" class="alert alert-error mb-3">{{ estadoDe(item.variable.id).error }}</div>
            <div class="grid-2 mb-3">
              <div class="form-group">
                <label class="form-label text-xs">Archivo</label>
                <input :ref="(el) => fileInputs[item.variable.id] = el as HTMLInputElement" type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" />
              </div>
              <div class="form-group">
                <label class="form-label text-xs">Fecha de emisión</label>
                <input v-model="estadoDe(item.variable.id).fechaEmision" type="date" class="form-control" />
              </div>
            </div>
            <span class="form-hint mb-2" style="display:block">PDF, JPG o PNG — máximo 10 MB</span>
            <button class="btn btn-primary btn-sm" :disabled="estadoDe(item.variable.id).subiendo" @click="subirEvidencia(item.variable.id)">
              {{ estadoDe(item.variable.id).subiendo ? 'Subiendo...' : 'Subir' }}
            </button>
          </div>

          <!-- Reutilizar — ya filtrado a este requisito -->
          <div v-if="estadoDe(item.variable.id).expandido === 'reutilizar'" class="mt-3" style="padding-top:0.75rem;border-top:1px solid var(--surface-border)">
            <div v-if="estadoDe(item.variable.id).cargandoReutilizables" class="text-sm text-muted">Cargando...</div>
            <div v-else-if="estadoDe(item.variable.id).misEvidencias.length === 0" class="text-sm text-muted">
              No tienes documentos previos reutilizables para este requisito.
            </div>
            <div v-else>
              <div v-for="m in estadoDe(item.variable.id).misEvidencias" :key="m.id" class="flex justify-between items-center mb-2" style="padding:0.5rem;border:1px solid var(--surface-border);border-radius:6px">
                <div>
                  <p class="font-medium text-sm">{{ m.nombre_original }}</p>
                  <p class="text-xs text-muted">{{ m.fecha_emision || 'sin fecha' }} · <span class="badge" :class="estadoEvidencia[m.estado]" style="font-size:0.65rem">{{ m.estado }}</span></p>
                </div>
                <button class="btn btn-primary btn-sm" @click="reutilizarEvidencia(item.variable.id, m.id)">Usar este</button>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
