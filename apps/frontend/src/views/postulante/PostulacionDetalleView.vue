<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import Icon from '@/components/ui/Icon.vue'
import Stepper from '@/components/ui/Stepper.vue'
import { pasoActualDe, pasosConEstado } from '@/utils/postulacionProgreso'

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
        <h1>{{ postulacion.plaza?.asignatura }}</h1>
        <p>{{ postulacion.convocatoria?.codigo }} · {{ postulacion.plaza?.facultad }} — {{ postulacion.plaza?.departamento }}</p>
      </div>
    </div>

    <div v-if="error" class="alert alert-error mb-4">{{ error }}</div>

    <div class="card mb-4">
      <Stepper :steps="pasosConEstado(postulacion)" :current-key="pasoActualDe(postulacion, !!resultado)" />

      <div v-if="postulacion.estado === 'observada'" class="alert alert-warning mt-4">
        <Icon name="alert-triangle" :size="16" />
        Tienes una evidencia observada — corrígela en tu expediente para que tu postulación pueda seguir avanzando.
      </div>
      <div v-else-if="postulacion.estado === 'rechazada'" class="alert alert-error mt-4">
        <Icon name="x-circle" :size="16" />
        Esta postulación fue rechazada.
        <span v-if="postulacion.motivo_rechazo">{{ postulacion.motivo_rechazo }}</span>
      </div>
    </div>

    <!-- Resultado (solo si ya fue publicado) — tarjeta única, centrada -->
    <div v-if="resultado" class="card mb-4 resultado-card" :class="`resultado-${resultado.estado}`">
      <Icon v-if="resultado.estado === 'ganador'" name="award" :size="28" class="resultado-icon" />
      <p class="resultado-estado">{{ resultadoEstadoLabel[resultado.estado] ?? resultado.estado }}</p>
      <p class="resultado-puntaje">{{ resultado.puntaje_total }} <span>pts</span></p>
      <p class="text-sm text-muted">Posición {{ resultado.posicion }} · publicado el {{ new Date(resultado.publicado_en).toLocaleDateString('es-PE') }}</p>
      <p class="text-xs text-muted mt-3">
        Si deseas apelar este resultado, cuentas con 5 días hábiles desde la fecha de publicación —
        el trámite se realiza directamente ante el Consejo Universitario, fuera de este sistema.
      </p>
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
      <span v-else-if="!resultado" class="text-sm text-muted flex items-center gap-2">
        <Icon name="check-circle" :size="16" /> Postulación enviada — en evaluación
      </span>
    </div>
  </div>
</template>
