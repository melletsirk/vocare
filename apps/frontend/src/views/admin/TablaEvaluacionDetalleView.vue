<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import Icon from '@/components/ui/Icon.vue'
import AccordionSection from '@/components/ui/AccordionSection.vue'

const route  = useRoute()
const router = useRouter()
const id     = route.params.id

const tabla   = ref<any>(null)
const loading = ref(true)
const activando  = ref(false)
const forkeando  = ref(false)
const erroresActivar = ref<string[]>([])

const abiertos = ref<Record<number, boolean>>({})

const estadoBadge: Record<string, string> = { activo: 'badge-green', borrador: 'badge-indigo', archivado: 'badge-gray' }
const estadoLabel: Record<string, string> = { activo: 'Activo', borrador: 'Borrador', archivado: 'Archivado' }

const editable = computed(() => tabla.value?.estado === 'borrador')

onMounted(cargar)

async function cargar() {
  loading.value = true
  try {
    const { data } = await api.get(`/tablas-evaluacion/${id}`)
    tabla.value = data
    if (Object.keys(abiertos.value).length === 0 && data.rubros?.length) {
      abiertos.value[data.rubros[0].id] = true
    }
  } finally {
    loading.value = false
  }
}

// ── Identidad del anexo (nombre/tipo_proceso/modalidad) ─────────────────────
async function guardarIdentidad() {
  await api.patch(`/tablas-evaluacion/${id}`, {
    nombre: tabla.value.nombre,
    tipo_proceso: tabla.value.tipo_proceso,
    modalidad: tabla.value.modalidad || null,
  })
}

// ── Rubros ───────────────────────────────────────────────────────────────
async function guardarRubro(rubro: any) {
  await api.patch(`/rubros/${rubro.id}`, {
    nombre: rubro.nombre,
    puntaje_max_subrubro: rubro.puntaje_max_subrubro,
  })
}

async function agregarRubro() {
  const nombre = prompt('Nombre del rubro:')
  if (!nombre) return
  const { data } = await api.post(`/tablas-evaluacion/${id}/rubros`, {
    nombre, orden: (tabla.value.rubros?.length ?? 0) + 1, puntaje_max_subrubro: 0,
  })
  tabla.value.rubros.push({ ...data, variables: [] })
  abiertos.value[data.id] = true
}

async function eliminarRubro(rubro: any) {
  if (!confirm(`¿Eliminar el rubro "${rubro.nombre}" y todas sus variables?`)) return
  await api.delete(`/rubros/${rubro.id}`)
  tabla.value.rubros = tabla.value.rubros.filter((r: any) => r.id !== rubro.id)
}

// ── Variables ────────────────────────────────────────────────────────────
async function guardarVariable(variable: any) {
  await api.patch(`/variables/${variable.id}`, {
    nombre: variable.nombre,
    puntaje_max: variable.puntaje_max,
    tipo_calculo: variable.tipo_calculo,
    periodo_validez_anios: variable.periodo_validez_anios || null,
    fuente_verificacion: variable.fuente_verificacion || null,
    fuente: variable.fuente || 'evidencia',
  })
}

async function agregarVariable(rubro: any) {
  const nombre = prompt('Nombre de la variable:')
  if (!nombre) return
  const { data } = await api.post(`/rubros/${rubro.id}/variables`, {
    nombre, orden: (rubro.variables?.length ?? 0) + 1, puntaje_max: 0, tipo_calculo: 'SUMA_CON_TOPE',
  })
  if (!rubro.variables) rubro.variables = []
  rubro.variables.push({ ...data, indicadores: [] })
}

async function eliminarVariable(rubro: any, variable: any) {
  if (!confirm(`¿Eliminar la variable "${variable.nombre}"?`)) return
  await api.delete(`/variables/${variable.id}`)
  rubro.variables = rubro.variables.filter((v: any) => v.id !== variable.id)
}

// ── Indicadores ──────────────────────────────────────────────────────────
async function guardarIndicador(indicador: any) {
  await api.patch(`/indicadores/${indicador.id}`, {
    nombre: indicador.nombre,
    puntaje: indicador.puntaje,
    tabla_equivalencia: indicador.tabla_equivalencia ?? null,
  })
}

async function agregarIndicadorSimple(variable: any) {
  const nombre = prompt('Nombre del indicador:')
  if (!nombre) return
  const { data } = await api.post(`/variables/${variable.id}/indicadores`, {
    nombre, puntaje: 0, orden: (variable.indicadores?.length ?? 0) + 1,
  })
  if (!variable.indicadores) variable.indicadores = []
  variable.indicadores.push(data)
}

// Rangos ilustrativos por defecto — el admin los ajusta a los valores reales
// del anexo (no hay forma de adivinarlos: dependen de la Resolución citada).
async function agregarIndicadorConTabla(variable: any) {
  const { data } = await api.post(`/variables/${variable.id}/indicadores`, {
    nombre: 'Nota / nivel (0–20)', puntaje: 0, orden: (variable.indicadores?.length ?? 0) + 1,
    tabla_equivalencia: [{ min: 0, max: 20, puntaje: variable.puntaje_max }],
  })
  if (!variable.indicadores) variable.indicadores = []
  variable.indicadores.push(data)
}

async function eliminarIndicador(variable: any, indicador: any) {
  if (!confirm('¿Eliminar este indicador?')) return
  await api.delete(`/indicadores/${indicador.id}`)
  variable.indicadores = variable.indicadores.filter((i: any) => i.id !== indicador.id)
}

function agregarRango(indicador: any) {
  if (!indicador.tabla_equivalencia) indicador.tabla_equivalencia = []
  indicador.tabla_equivalencia.push({ min: 0, max: 20, puntaje: 0 })
}
function quitarRango(indicador: any, idx: number) {
  indicador.tabla_equivalencia.splice(idx, 1)
  guardarIndicador(indicador)
}

// ── Mínimos ──────────────────────────────────────────────────────────────
async function guardarMinimos() {
  await api.patch(`/tablas-evaluacion/${id}`, {
    puntaje_minimo_aprobatorio: tabla.value.puntaje_minimo_aprobatorio || null,
    minimos_subrubro: tabla.value.minimos_subrubro || [],
  })
}
function agregarSubMinimo() {
  if (!tabla.value.minimos_subrubro) tabla.value.minimos_subrubro = []
  tabla.value.minimos_subrubro.push({ nombre: '', rubro_ids: [], minimo: 0 })
}
function quitarSubMinimo(idx: number) {
  tabla.value.minimos_subrubro.splice(idx, 1)
  guardarMinimos()
}
function toggleRubroEnMinimo(grupo: any, rubroId: number) {
  const idx = grupo.rubro_ids.indexOf(rubroId)
  if (idx === -1) grupo.rubro_ids.push(rubroId)
  else grupo.rubro_ids.splice(idx, 1)
  guardarMinimos()
}

// ── Activar / forkear ────────────────────────────────────────────────────
async function activar() {
  activando.value = true
  erroresActivar.value = []
  try {
    const { data } = await api.post(`/tablas-evaluacion/${id}/activar`)
    tabla.value = data
  } catch (e: any) {
    erroresActivar.value = e.response?.data?.errores ?? [e.response?.data?.message ?? 'No se pudo activar.']
  } finally {
    activando.value = false
  }
}

async function nuevaVersion() {
  forkeando.value = true
  try {
    const { data } = await api.post('/tablas-evaluacion', { clonar_de_id: id })
    router.push(`/admin/tablas-evaluacion/${data.id}`)
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo crear la nueva versión')
  } finally {
    forkeando.value = false
  }
}

function metaRubro(rubro: any): string {
  return `tope ${rubro.puntaje_max_subrubro} pts · ${rubro.variables?.length ?? 0} variable${(rubro.variables?.length ?? 0) === 1 ? '' : 's'}`
}
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

  <div v-else-if="tabla">
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm mb-1" @click="router.back()">← Tablas de evaluación</button>
        <h1>{{ tabla.nombre }}</h1>
        <p>
          {{ tabla.codigo_anexo }}
          <span v-if="tabla.reglamento_version"> · {{ tabla.reglamento_version.numero_version }} ({{ tabla.reglamento_version.nombre }})</span>
          <span v-if="tabla.version_anterior"> · reemplaza a v{{ tabla.version_anterior.id }} ({{ estadoLabel[tabla.version_anterior.estado] }})</span>
        </p>
      </div>
      <div class="flex items-center gap-2">
        <span class="badge" :class="estadoBadge[tabla.estado] ?? 'badge-gray'">{{ estadoLabel[tabla.estado] ?? tabla.estado }}</span>
        <button v-if="editable" class="btn btn-primary btn-sm" :disabled="activando" @click="activar">
          <span v-if="activando" class="spinner"></span>
          {{ activando ? 'Activando...' : 'Activar' }}
        </button>
        <button v-else class="btn btn-secondary btn-sm" :disabled="forkeando" @click="nuevaVersion">
          <span v-if="forkeando" class="spinner"></span>
          Nueva versión desde esta →
        </button>
      </div>
    </div>

    <div v-if="erroresActivar.length" class="alert alert-error mb-4">
      <div>
        <p class="font-medium mb-1">No se pudo activar — hay errores de validación:</p>
        <ul style="padding-left:1.1rem">
          <li v-for="(e, i) in erroresActivar" :key="i" class="text-sm">{{ e }}</li>
        </ul>
      </div>
    </div>

    <!-- Identidad -->
    <div class="card mb-4">
      <div class="grid-2">
        <div class="form-group">
          <label class="form-label text-xs">Nombre</label>
          <input v-model="tabla.nombre" class="form-control" :disabled="!editable" @blur="guardarIdentidad" />
        </div>
        <div class="form-group">
          <label class="form-label text-xs">Tipo de proceso</label>
          <input v-model="tabla.tipo_proceso" class="form-control" :disabled="!editable" @blur="guardarIdentidad" />
        </div>
      </div>
      <div class="form-group mt-3">
        <label class="form-label text-xs">Modalidad</label>
        <input v-model="tabla.modalidad" class="form-control" placeholder="(sin modalidad específica)" :disabled="!editable" @blur="guardarIdentidad" />
      </div>
    </div>

    <!-- Rubros -->
    <AccordionSection
      v-for="rubro in tabla.rubros"
      :key="rubro.id"
      :title="rubro.nombre"
      :meta="metaRubro(rubro)"
      :open="!!abiertos[rubro.id]"
      @update:open="(v) => abiertos[rubro.id] = v"
    >
      <div v-if="editable" class="flex gap-2 items-end mb-3">
        <div class="form-group" style="flex:1">
          <label class="form-label text-xs">Nombre del rubro</label>
          <input v-model="rubro.nombre" class="form-control" @blur="guardarRubro(rubro)" />
        </div>
        <div class="form-group" style="width:120px">
          <label class="form-label text-xs">Tope sub-rubro</label>
          <input v-model.number="rubro.puntaje_max_subrubro" type="number" step="0.1" class="form-control" @blur="guardarRubro(rubro)" />
        </div>
        <button class="btn btn-ghost btn-icon btn-sm" title="Eliminar rubro" @click="eliminarRubro(rubro)"><Icon name="x" :size="14" /></button>
      </div>

      <div v-for="variable in rubro.variables" :key="variable.id" class="requisito-row">
        <div class="var-edit-row">
          <input v-model="variable.nombre" class="form-control" style="flex:2" :disabled="!editable" @blur="guardarVariable(variable)" />
          <input v-model.number="variable.puntaje_max" type="number" step="0.1" class="form-control" style="width:80px" :disabled="!editable" @blur="guardarVariable(variable)" title="Puntaje máximo" />
          <select v-model="variable.tipo_calculo" class="form-control" style="width:170px" :disabled="!editable" @change="guardarVariable(variable)">
            <option value="SUMA_CON_TOPE">SUMA_CON_TOPE</option>
            <option value="MAYOR_VALOR">MAYOR_VALOR</option>
            <option value="TABLA_EQUIVALENCIA">TABLA_EQUIVALENCIA</option>
            <option value="DATO_INSTITUCIONAL">DATO_INSTITUCIONAL</option>
          </select>
          <select v-model="variable.fuente" class="form-control" style="width:120px" :disabled="!editable" @change="guardarVariable(variable)" title="Fuente del puntaje">
            <option value="evidencia">evidencia</option>
            <option value="etapa">etapa</option>
          </select>
          <button v-if="editable" class="btn btn-ghost btn-icon btn-sm" title="Eliminar variable" @click="eliminarVariable(rubro, variable)"><Icon name="x" :size="14" /></button>
        </div>

        <div class="flex gap-3 mt-2" style="flex-wrap:wrap">
          <div class="form-group" style="width:160px">
            <label class="form-label text-xs">Vigencia (años)</label>
            <input v-model.number="variable.periodo_validez_anios" type="number" class="form-control" placeholder="sin vencimiento" :disabled="!editable" @blur="guardarVariable(variable)" />
          </div>
          <div v-if="variable.tipo_calculo === 'DATO_INSTITUCIONAL'" class="form-group" style="flex:1;min-width:220px">
            <label class="form-label text-xs">Fuente de verificación</label>
            <input v-model="variable.fuente_verificacion" class="form-control" placeholder="ej. Centro de Desarrollo Académico" :disabled="!editable" @blur="guardarVariable(variable)" />
          </div>
        </div>

        <!-- Indicadores — MAYOR_VALOR: lista simple -->
        <div v-if="variable.tipo_calculo === 'MAYOR_VALOR'" class="mt-3">
          <div v-for="ind in variable.indicadores" :key="ind.id" class="flex gap-2 items-center mb-1">
            <input v-model="ind.nombre" class="form-control" style="flex:1" :disabled="!editable" @blur="guardarIndicador(ind)" />
            <input v-model.number="ind.puntaje" type="number" step="0.01" class="form-control" style="width:90px" :disabled="!editable" @blur="guardarIndicador(ind)" />
            <button v-if="editable" class="btn btn-ghost btn-icon btn-sm" @click="eliminarIndicador(variable, ind)"><Icon name="x" :size="12" /></button>
          </div>
          <button v-if="editable" class="btn btn-ghost btn-sm" @click="agregarIndicadorSimple(variable)">+ Agregar indicador</button>
        </div>

        <!-- Indicadores — TABLA_EQUIVALENCIA: editor de rangos -->
        <div v-else-if="variable.tipo_calculo === 'TABLA_EQUIVALENCIA'" class="mt-3">
          <div v-for="ind in variable.indicadores" :key="ind.id" class="rango-indicador">
            <div v-for="(r, ri) in (ind.tabla_equivalencia || [])" :key="ri" class="rango-row">
              <input v-model.number="r.min" type="number" class="form-control" style="width:70px" :disabled="!editable" @blur="guardarIndicador(ind)" />
              <span class="text-xs text-muted">–</span>
              <input v-model.number="r.max" type="number" class="form-control" style="width:70px" :disabled="!editable" @blur="guardarIndicador(ind)" />
              <span class="text-xs text-muted">→</span>
              <input v-model.number="r.puntaje" type="number" step="0.1" class="form-control" style="width:80px" :disabled="!editable" @blur="guardarIndicador(ind)" />
              <span class="text-xs text-muted">pts</span>
              <button v-if="editable" class="btn btn-ghost btn-icon btn-sm" @click="quitarRango(ind, Number(ri))"><Icon name="x" :size="12" /></button>
            </div>
            <button v-if="editable" class="btn btn-ghost btn-sm" @click="agregarRango(ind)">+ Agregar rango</button>
          </div>
          <button v-if="editable && !variable.indicadores?.length" class="btn btn-ghost btn-sm" @click="agregarIndicadorConTabla(variable)">
            + Agregar tabla de rangos
          </button>
        </div>
      </div>

      <button v-if="editable" class="btn btn-ghost btn-sm mt-2" @click="agregarVariable(rubro)">+ Agregar variable</button>
    </AccordionSection>

    <button v-if="editable" class="btn btn-secondary btn-sm mt-2 mb-4" @click="agregarRubro">+ Agregar rubro</button>

    <!-- Mínimos de aprobación -->
    <div class="card mt-4">
      <div class="card-header">
        <h3 class="card-title">Mínimos de aprobación</h3>
      </div>
      <div class="form-group mb-3">
        <label class="form-label text-xs">Mínimo total</label>
        <input v-model.number="tabla.puntaje_minimo_aprobatorio" type="number" step="0.1" class="form-control" style="max-width:160px" placeholder="sin mínimo configurado" :disabled="!editable" @blur="guardarMinimos" />
      </div>

      <div v-for="(grupo, gi) in (tabla.minimos_subrubro || [])" :key="gi" class="sub-minimo-row">
        <input v-model="grupo.nombre" class="form-control" style="width:200px" placeholder="ej. Aptitud Docente" :disabled="!editable" @blur="guardarMinimos" />
        <div class="sub-minimo-chips">
          <button
            v-for="rubro in tabla.rubros"
            :key="rubro.id"
            type="button"
            class="badge sub-minimo-chip"
            :class="grupo.rubro_ids.includes(rubro.id) ? 'badge-blue' : 'badge-gray'"
            :disabled="!editable"
            @click="toggleRubroEnMinimo(grupo, rubro.id)"
          >{{ rubro.nombre }}</button>
        </div>
        <input v-model.number="grupo.minimo" type="number" step="0.1" class="form-control" style="width:90px" :disabled="!editable" @blur="guardarMinimos" />
        <button v-if="editable" class="btn btn-ghost btn-icon btn-sm" @click="quitarSubMinimo(Number(gi))"><Icon name="x" :size="14" /></button>
      </div>
      <button v-if="editable" class="btn btn-ghost btn-sm mt-2" @click="agregarSubMinimo">+ Agregar sub-mínimo</button>
    </div>
  </div>
</template>
