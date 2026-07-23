<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import Stepper from '@/components/ui/Stepper.vue'
import type { StepperStep } from '@/components/ui/Stepper.vue'

const router  = useRouter()
const saving  = ref(false)
const error   = ref('')
const tablas  = ref<any[]>([])

const form = reactive({
  codigo: '', nombre: '', tipo_proceso: '', modalidad: null as string | null, descripcion: '',
  tabla_evaluacion_id: '', fecha_inicio: '', fecha_fin: '',
})

const PASOS: StepperStep[] = [
  { key: 'anexo',    label: 'Tabla de evaluación' },
  { key: 'datos',    label: 'Datos generales' },
  { key: 'revision', label: 'Revisión' },
]
const pasoActual = ref<'anexo' | 'datos' | 'revision'>('anexo')

onMounted(async () => {
  const { data } = await api.get('/tablas-evaluacion')
  tablas.value = data
})

// tipo_proceso/modalidad son propiedades de la tabla elegida, no datos
// independientes — se derivan automáticamente para que nunca puedan quedar
// inconsistentes con el Anexo seleccionado (el backend valida esto).
function onTablaChange() {
  const tabla = tablas.value.find((t) => t.id === form.tabla_evaluacion_id)
  form.tipo_proceso = tabla?.tipo_proceso ?? ''
  form.modalidad = tabla?.modalidad ?? null
}

const tablaElegida = computed(() => tablas.value.find((t) => t.id === form.tabla_evaluacion_id))

const puedeAvanzarAnexo = computed(() => !!form.tabla_evaluacion_id)
const puedeAvanzarDatos = computed(() => !!form.codigo && !!form.nombre && !!form.fecha_inicio && !!form.fecha_fin)

function siguiente() {
  if (pasoActual.value === 'anexo' && puedeAvanzarAnexo.value) pasoActual.value = 'datos'
  else if (pasoActual.value === 'datos' && puedeAvanzarDatos.value) pasoActual.value = 'revision'
}
function atras() {
  if (pasoActual.value === 'revision') pasoActual.value = 'datos'
  else if (pasoActual.value === 'datos') pasoActual.value = 'anexo'
}

async function guardar() {
  error.value  = ''
  saving.value = true
  try {
    const { data } = await api.post('/convocatorias', form)
    router.push(`/convocatorias/${data.id}`)
  } catch (e: any) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : e.response?.data?.message || 'Error al guardar'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div style="max-width:720px">
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm mb-1" @click="router.back()">← Volver</button>
        <h1>Nueva convocatoria</h1>
      </div>
    </div>

    <div class="card mb-4">
      <Stepper :steps="PASOS" :current-key="pasoActual" />
    </div>

    <div class="card">
      <div v-if="error" class="alert alert-error mb-4">{{ error }}</div>

      <!-- Paso 1: Tabla de evaluación -->
      <template v-if="pasoActual === 'anexo'">
        <p class="text-sm text-muted mb-4">
          Elige el anexo del reglamento aplicable — el tipo de proceso y la modalidad se derivan
          automáticamente de esta elección, para que nunca queden inconsistentes.
        </p>
        <div class="form-group mb-4">
          <label class="form-label">Tabla de evaluación (Anexo) <span class="required">*</span></label>
          <select v-model="form.tabla_evaluacion_id" class="form-control" @change="onTablaChange">
            <option value="">Seleccionar anexo...</option>
            <option v-for="t in tablas" :key="t.id" :value="t.id">
              {{ t.codigo_anexo }} — {{ t.nombre }} ({{ t.tipo_proceso }}{{ t.modalidad ? ' · ' + t.modalidad : '' }})
            </option>
          </select>
        </div>
        <div v-if="tablaElegida" class="alert alert-info">
          Tipo de proceso: <strong>{{ form.tipo_proceso }}</strong>
          <span v-if="form.modalidad"> · Modalidad: <strong>{{ form.modalidad }}</strong></span>
        </div>
      </template>

      <!-- Paso 2: Datos generales -->
      <template v-else-if="pasoActual === 'datos'">
        <div class="form-group mb-4">
          <label class="form-label">Código <span class="required">*</span></label>
          <input v-model="form.codigo" class="form-control" placeholder="CONV-2026-001" />
        </div>

        <div class="form-group mb-4">
          <label class="form-label">Nombre de la convocatoria <span class="required">*</span></label>
          <input v-model="form.nombre" class="form-control" placeholder="Convocatoria Docente 2026-I" />
        </div>

        <div class="form-group mb-4">
          <label class="form-label">Descripción</label>
          <textarea v-model="form.descripcion" class="form-control" rows="3" placeholder="Descripción del proceso..."></textarea>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Fecha inicio <span class="required">*</span></label>
            <input v-model="form.fecha_inicio" type="date" class="form-control" />
          </div>
          <div class="form-group">
            <label class="form-label">Fecha fin <span class="required">*</span></label>
            <input v-model="form.fecha_fin" type="date" class="form-control" />
          </div>
        </div>
      </template>

      <!-- Paso 3: Revisión -->
      <template v-else>
        <div class="revision-list mb-4">
          <div class="revision-row"><span class="text-sm text-muted">Anexo</span><span class="font-medium">{{ tablaElegida?.codigo_anexo }} — {{ tablaElegida?.nombre }}</span></div>
          <div class="revision-row"><span class="text-sm text-muted">Tipo de proceso</span><span class="font-medium">{{ form.tipo_proceso }}<span v-if="form.modalidad"> · {{ form.modalidad }}</span></span></div>
          <div class="revision-row"><span class="text-sm text-muted">Código</span><span class="font-medium">{{ form.codigo }}</span></div>
          <div class="revision-row"><span class="text-sm text-muted">Nombre</span><span class="font-medium">{{ form.nombre }}</span></div>
          <div class="revision-row"><span class="text-sm text-muted">Vigencia</span><span class="font-medium">{{ form.fecha_inicio }} → {{ form.fecha_fin }}</span></div>
        </div>
        <p class="text-sm text-muted mb-4">
          Se creará en borrador. Después de crearla podrás agregar las plazas y publicarla desde su página de detalle.
        </p>
      </template>

      <div class="flex justify-between mt-2">
        <button v-if="pasoActual !== 'anexo'" class="btn btn-secondary" @click="atras">← Atrás</button>
        <span v-else></span>

        <button
          v-if="pasoActual !== 'revision'"
          class="btn btn-primary"
          :disabled="(pasoActual === 'anexo' && !puedeAvanzarAnexo) || (pasoActual === 'datos' && !puedeAvanzarDatos)"
          @click="siguiente"
        >
          Siguiente →
        </button>
        <button v-else class="btn btn-primary" :disabled="saving" @click="guardar">
          <span v-if="saving" class="spinner"></span>
          {{ saving ? 'Creando...' : 'Crear convocatoria' }}
        </button>
      </div>
    </div>
  </div>
</template>
