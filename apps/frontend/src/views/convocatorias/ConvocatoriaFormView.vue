<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'

const router  = useRouter()
const saving  = ref(false)
const error   = ref('')
const tablas  = ref<any[]>([])

const form = reactive({
  codigo: '', nombre: '', tipo_proceso: '', descripcion: '',
  tabla_evaluacion_id: '', fecha_inicio: '', fecha_fin: '',
})

onMounted(async () => {
  const { data } = await api.get('/tablas-evaluacion')
  tablas.value = data
})

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

    <div class="card">
      <div v-if="error" class="alert alert-error mb-4">{{ error }}</div>

      <div class="grid-2 mb-4">
        <div class="form-group">
          <label class="form-label">Código <span class="required">*</span></label>
          <input v-model="form.codigo" class="form-control" placeholder="CONV-2025-001" />
        </div>
        <div class="form-group">
          <label class="form-label">Tipo de proceso <span class="required">*</span></label>
          <select v-model="form.tipo_proceso" class="form-control">
            <option value="">Seleccionar...</option>
            <option value="Ordinario">Ordinario</option>
            <option value="Extraordinario">Extraordinario</option>
            <option value="Contratacion">Contratación</option>
          </select>
        </div>
      </div>

      <div class="form-group mb-4">
        <label class="form-label">Nombre de la convocatoria <span class="required">*</span></label>
        <input v-model="form.nombre" class="form-control" placeholder="Convocatoria Docente 2025-I" />
      </div>

      <div class="form-group mb-4">
        <label class="form-label">Descripción</label>
        <textarea v-model="form.descripcion" class="form-control" rows="3" placeholder="Descripción del proceso..."></textarea>
      </div>

      <div class="form-group mb-4">
        <label class="form-label">Tabla de evaluación (Anexo) <span class="required">*</span></label>
        <select v-model="form.tabla_evaluacion_id" class="form-control">
          <option value="">Seleccionar anexo...</option>
          <option v-for="t in tablas" :key="t.id" :value="t.id">
            {{ t.codigo_anexo }} — {{ t.nombre }} ({{ t.tipo_proceso }})
          </option>
        </select>
      </div>

      <div class="grid-2 mb-6">
        <div class="form-group">
          <label class="form-label">Fecha inicio <span class="required">*</span></label>
          <input v-model="form.fecha_inicio" type="date" class="form-control" />
        </div>
        <div class="form-group">
          <label class="form-label">Fecha fin <span class="required">*</span></label>
          <input v-model="form.fecha_fin" type="date" class="form-control" />
        </div>
      </div>

      <div class="flex gap-3">
        <button class="btn btn-primary" :disabled="saving" @click="guardar">
          <span v-if="saving" class="spinner"></span>
          {{ saving ? 'Guardando...' : 'Crear convocatoria' }}
        </button>
        <button class="btn btn-secondary" @click="router.back()">Cancelar</button>
      </div>
    </div>
  </div>
</template>
