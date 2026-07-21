<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route  = useRoute()
const router = useRouter()
const id     = route.params.id

const postulacion = ref<any>(null)
const resultado   = ref<any>(null)
const loading     = ref(true)
const sending     = ref(false)
const error       = ref('')

const resultadoEstadoLabel: Record<string, string> = {
  ganador: 'Ganador/a', reserva: 'Reserva', no_ganador: 'No ganador/a', desierta: 'Plaza desierta',
}
const resultadoEstadoBadge: Record<string, string> = {
  ganador: 'badge-green', reserva: 'badge-blue', no_ganador: 'badge-gray', desierta: 'badge-red',
}

onMounted(async () => {
  try {
    const { data } = await api.get(`/postulaciones/${id}`)
    postulacion.value = data
  } finally {
    loading.value = false
  }

  // Solo existe una vez publicados los resultados; si no, el backend
  // responde 404 (NO_PUBLICADOS) — se ignora silenciosamente.
  try {
    const { data } = await api.get(`/postulaciones/${id}/resultado`)
    resultado.value = data
  } catch {
    resultado.value = null
  }
})

const estadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', observada: 'badge-indigo',
  rechazada: 'badge-red', aprobada_etapa: 'badge-green', ganadora: 'badge-blue',
}

async function enviar() {
  if (!confirm('¿Estás seguro de enviar tu postulación? Una vez enviada no podrás modificar tu expediente.')) return
  error.value   = ''
  sending.value = true
  try {
    await api.post(`/postulaciones/${id}/enviar`, { cv_datos: {} })
    const { data } = await api.get(`/postulaciones/${id}`)
    postulacion.value = data
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al enviar la postulación.'
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

  <div v-else-if="postulacion">
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm mb-1" @click="router.back()">← Volver</button>
        <h1>Detalle de postulación</h1>
        <p>{{ postulacion.plaza?.asignatura }} — {{ postulacion.convocatoria?.nombre }}</p>
      </div>
      <span class="badge" :class="estadoBadge[postulacion.estado] || 'badge-gray'">
        {{ postulacion.estado?.replace(/_/g, ' ') || 'En proceso' }}
      </span>
    </div>

    <div v-if="error" class="alert alert-error mb-4">{{ error }}</div>

    <!-- Info convocatoria -->
    <div class="card mb-4">
      <div class="card-header">
        <h3 class="card-title">Datos de la convocatoria</h3>
      </div>
      <div class="grid-2" style="padding:1.25rem;gap:1rem">
        <div>
          <div class="text-sm text-muted">Código</div>
          <div class="font-medium">{{ postulacion.convocatoria?.codigo }}</div>
        </div>
        <div>
          <div class="text-sm text-muted">Tipo de proceso</div>
          <div class="font-medium">{{ postulacion.convocatoria?.tipo_proceso }}</div>
        </div>
        <div>
          <div class="text-sm text-muted">Facultad</div>
          <div class="font-medium">{{ postulacion.plaza?.facultad }}</div>
        </div>
        <div>
          <div class="text-sm text-muted">Departamento</div>
          <div class="font-medium">{{ postulacion.plaza?.departamento }}</div>
        </div>
        <div>
          <div class="text-sm text-muted">Plaza</div>
          <div class="font-medium">{{ postulacion.plaza?.asignatura }}</div>
        </div>
        <div>
          <div class="text-sm text-muted">Envío formal</div>
          <div class="font-medium">
            <span v-if="postulacion.fecha_envio">
              {{ new Date(postulacion.fecha_envio).toLocaleString('es-PE') }}
            </span>
            <span v-else class="badge badge-gray" style="font-size:0.7rem">Borrador — no enviada</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Resultado (solo si ya fue publicado) -->
    <div v-if="resultado" class="card mb-4">
      <div class="card-header">
        <h3 class="card-title">Resultado</h3>
        <span class="badge" :class="resultadoEstadoBadge[resultado.estado] ?? 'badge-gray'">
          {{ resultadoEstadoLabel[resultado.estado] ?? resultado.estado }}
        </span>
      </div>
      <div class="grid-2" style="padding:1.25rem;gap:1rem">
        <div>
          <div class="text-sm text-muted">Puntaje total</div>
          <div class="font-semibold text-lg">{{ resultado.puntaje_total }}</div>
        </div>
        <div>
          <div class="text-sm text-muted">Posición</div>
          <div class="font-semibold text-lg">{{ resultado.posicion }}</div>
        </div>
        <div style="grid-column:span 2">
          <div class="text-sm text-muted">Publicado</div>
          <div class="font-medium">{{ new Date(resultado.publicado_en).toLocaleString('es-PE') }}</div>
        </div>
      </div>
    </div>

    <!-- Acciones -->
    <div class="flex gap-3 items-center">
      <RouterLink :to="`/mis-postulaciones/${id}/expediente`" class="btn btn-secondary">
        Ir al expediente →
      </RouterLink>
      <button
        v-if="!postulacion.fecha_envio"
        class="btn btn-primary"
        :disabled="sending"
        @click="enviar"
      >
        <span v-if="sending" class="spinner"></span>
        {{ sending ? 'Enviando...' : 'Enviar postulación formalmente' }}
      </button>
      <span v-else class="text-sm text-muted">✅ Postulación enviada</span>
    </div>
  </div>
</template>
