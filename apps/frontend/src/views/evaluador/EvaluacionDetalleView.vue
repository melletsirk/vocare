<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route  = useRoute()
const router = useRouter()
const id     = route.params.id

const evaluacion = ref<any>(null)
const desglose   = ref<any>(null)
const loading    = ref(true)
const calculando = ref(false)
const cerrando   = ref(false)

onMounted(cargar)

async function cargar() {
  loading.value = true
  try {
    const { data } = await api.get(`/evaluaciones/${id}`)
    evaluacion.value = data
    if (data.puntaje_total) {
      const dRes = await api.get(`/evaluaciones/${id}/desglose`)
      desglose.value = dRes.data
    }
  } finally {
    loading.value = false
  }
}

async function calcular() {
  calculando.value = true
  try {
    await api.post(`/evaluaciones/${id}/calcular`)
    await cargar()
  } finally {
    calculando.value = false
  }
}

async function cerrar() {
  if (!confirm('¿Cerrar la evaluación? Esta acción es irreversible.')) return
  cerrando.value = true
  try {
    await api.post(`/evaluaciones/${id}/cerrar`)
    await cargar()
  } finally {
    cerrando.value = false
  }
}

const estadoBadge: Record<string, string> = {
  en_proceso:'badge-yellow', completada:'badge-blue', cerrada:'badge-green',
}
const evidEstado: Record<string, string> = {
  pendiente:'badge-yellow', aprobada:'badge-green', observada:'badge-indigo', rechazada:'badge-red',
}
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

  <div v-else-if="evaluacion">
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm mb-1" @click="router.back()">← Volver</button>
        <h1>Evaluación #{{ evaluacion.id }}</h1>
        <p>{{ evaluacion.postulacion?.postulante?.name }} — {{ evaluacion.postulacion?.plaza?.asignatura }}</p>
      </div>
      <div class="flex gap-2">
        <span class="badge" :class="estadoBadge[evaluacion.estado]">{{ evaluacion.estado }}</span>
      </div>
    </div>

    <!-- Puntaje total -->
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
          <div class="stat-value">{{ evaluacion.postulacion?.expediente?.evidencias?.length ?? 0 }}</div>
          <div class="stat-label">Evidencias en expediente</div>
        </div>
      </div>
    </div>

    <!-- Evidencias -->
    <div class="card mb-4">
      <div class="card-header">
        <h3 class="card-title">Documentos del expediente</h3>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Variable</th><th>Archivo</th><th>Estado</th><th>Observación</th></tr>
          </thead>
          <tbody>
            <tr v-for="ev in evaluacion.postulacion?.expediente?.evidencias" :key="ev.id">
              <td class="text-sm">{{ ev.variable?.nombre ?? '—' }}</td>
              <td class="font-medium text-sm">{{ ev.nombre_original }}</td>
              <td><span class="badge" :class="evidEstado[ev.estado]">{{ ev.estado }}</span></td>
              <td class="text-sm text-muted">{{ ev.comentario_observacion || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Desglose de puntajes -->
    <div v-if="desglose" class="card mb-4">
      <div class="card-header">
        <h3 class="card-title">Desglose por Sub Rubro</h3>
        <span class="badge badge-blue">{{ desglose.tabla_nombre }}</span>
      </div>
      <div v-for="rubro in desglose.rubros" :key="rubro.nombre" class="mb-4">
        <div class="flex justify-between items-center mb-2">
          <h4 class="font-medium">{{ rubro.nombre }}</h4>
          <div class="flex items-center gap-2">
            <span v-if="rubro.tope_aplicado" class="badge badge-yellow">Tope aplicado</span>
            <span class="font-semibold" style="color:var(--clr-primary-700)">
              {{ rubro.puntaje_final }} / {{ rubro.puntaje_max }} pts
            </span>
          </div>
        </div>
        <div class="progress-bar mb-2">
          <div class="progress-fill" :style="{ width: (rubro.puntaje_final / rubro.puntaje_max * 100) + '%' }"></div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Variable</th><th>Tipo</th><th>Bruto</th><th>Aplicado</th><th>Máx.</th></tr>
            </thead>
            <tbody>
              <tr v-for="v in rubro.variables" :key="v.variable_id">
                <td class="text-sm">{{ v.nombre }}</td>
                <td><span class="badge badge-gray text-xs">{{ v.tipo_calculo }}</span></td>
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

    <div v-else class="alert alert-success">
      ✅ Evaluación cerrada el {{ new Date(evaluacion.cerrada_en).toLocaleDateString('es-PE') }}.
      Puntaje final: <strong>{{ evaluacion.puntaje_total }}</strong>
    </div>
  </div>
</template>
